<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Drivers\Sqs;

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

/**
 * Service for sending SQS metrics to AWS CloudWatch
 */
class CloudWatchMetricsService
{
    private CloudWatchClient $cloudWatchClient;
    private string $namespace;
    private bool $enabled;

    public function __construct(?CloudWatchClient $cloudWatchClient = null)
    {
        $this->enabled = config('sqs.cloudwatch.enabled', true);
        
        if (!$this->enabled) {
            return;
        }

        $this->cloudWatchClient = $cloudWatchClient ?? new CloudWatchClient([
            'region' => config('aws.region', env('AWS_DEFAULT_REGION', 'us-east-2')),
            'version' => 'latest',
            'credentials' => [
                'key' => config('aws.key', env('AWS_SQS_ACCESS_KEY_ID')),
                'secret' => config('aws.secret', env('AWS_SQS_SECRET_ACCESS_KEY')),
            ],
        ]);

        $this->namespace = config('sqs.cloudwatch.namespace', 'SQS/PaymentService');
    }

    /**
     * Send a metric to CloudWatch
     *
     * @param string $metricName Metric name (e.g., 'sqs.messages.processed')
     * @param float $value Metric value
     * @param string $unit Unit of measurement (Count, Seconds, etc.)
     * @param array $dimensions Additional dimensions (e.g., ['Queue' => 'payment-service-queue', 'EventType' => 'StudentEnrolled'])
     */
    public function putMetric(
        string $metricName,
        float $value,
        string $unit = 'Count',
        array $dimensions = []
    ): void {
        if (!$this->enabled) {
            return;
        }

        try {
            $metricData = [
                'MetricName' => $metricName,
                'Value' => $value,
                'Unit' => $unit,
                'Timestamp' => now()->toIso8601String(),
            ];

            if (!empty($dimensions)) {
                $formattedDimensions = [];
                foreach ($dimensions as $name => $value) {
                    $formattedDimensions[] = [
                        'Name' => $name,
                        'Value' => (string) $value,
                    ];
                }
                $metricData['Dimensions'] = $formattedDimensions;
            }

            $this->cloudWatchClient->putMetricData([
                'Namespace' => $this->namespace,
                'MetricData' => [$metricData],
            ]);
        } catch (AwsException $e) {
            // Log error but don't throw - metrics failure shouldn't break message processing
            Log::warning('CloudWatch metric send failed', [
                'metric_name' => $metricName,
                'error' => $e->getAwsErrorMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CloudWatch metric send error', [
                'metric_name' => $metricName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Increment a counter metric
     *
     * @param string $metricName Metric name
     * @param float $value Value to increment by (default: 1)
     * @param array $dimensions Additional dimensions
     */
    public function increment(string $metricName, float $value = 1.0, array $dimensions = []): void
    {
        $this->putMetric($metricName, $value, 'Count', $dimensions);
    }

    /**
     * Record processing duration
     *
     * @param string $metricName Metric name
     * @param float $duration Duration in seconds
     * @param array $dimensions Additional dimensions
     */
    public function recordDuration(string $metricName, float $duration, array $dimensions = []): void
    {
        $this->putMetric($metricName, $duration, 'Seconds', $dimensions);
    }

    /**
     * Send multiple metrics in a single batch (more efficient)
     *
     * @param array $metrics Array of metric data: [['name' => 'metric1', 'value' => 1.0, 'unit' => 'Count', 'dimensions' => []]]
     */
    public function putMetrics(array $metrics): void
    {
        if (!$this->enabled || empty($metrics)) {
            return;
        }

        try {
            $metricData = [];
            foreach ($metrics as $metric) {
                $data = [
                    'MetricName' => $metric['name'],
                    'Value' => $metric['value'] ?? 1.0,
                    'Unit' => $metric['unit'] ?? 'Count',
                    'Timestamp' => now()->toIso8601String(),
                ];

                if (!empty($metric['dimensions'])) {
                    $formattedDimensions = [];
                    foreach ($metric['dimensions'] as $name => $value) {
                        $formattedDimensions[] = [
                            'Name' => $name,
                            'Value' => (string) $value,
                        ];
                    }
                    $data['Dimensions'] = $formattedDimensions;
                }

                $metricData[] = $data;
            }

            // CloudWatch allows up to 20 metrics per request
            $chunks = array_chunk($metricData, 20);
            foreach ($chunks as $chunk) {
                $this->cloudWatchClient->putMetricData([
                    'Namespace' => $this->namespace,
                    'MetricData' => $chunk,
                ]);
            }
        } catch (AwsException $e) {
            Log::warning('CloudWatch batch metric send failed', [
                'metric_count' => count($metrics),
                'error' => $e->getAwsErrorMessage(),
                'code' => $e->getAwsErrorCode(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CloudWatch batch metric send error', [
                'metric_count' => count($metrics),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

