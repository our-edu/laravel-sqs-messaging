<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Drivers\Sqs;

use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SQSResolver
{
    private SqsClient $sqsClient;

    public function __construct(?SqsClient $sqsClient = null)
    {
        $this->sqsClient = $sqsClient ?? app(SqsClient::class);
    }

    /**
     * Resolve queue URL with caching (30 days)
     * Creates queue and DLQ if they don't exist
     * @throws \Exception
     */
    public function resolve(string $queueName): string
    {
        // Apply environment prefix
        $resolvedQueueName = $this->resolveQueueName($queueName);
        $cacheKey = "sqs_queue_url_{$resolvedQueueName}";
        return Cache::remember($cacheKey, now()->addDays(30), function () use ($resolvedQueueName) {
            $createQueueUrlResponse = $this->getQueueUrl($resolvedQueueName);
            if ($createQueueUrlResponse['status'] == 200) {
                return $createQueueUrlResponse['queue_url'];
            } elseif ($createQueueUrlResponse['status'] == 400) {
                return $this->createQueue($resolvedQueueName);
            } else {
                throw new \Exception("Error retrieving queue URL: " . $createQueueUrlResponse['message']);
            }
        });
    }

    /**
     * Resolve queue name with environment prefix
     */
    private function resolveQueueName(string $baseName): string
    {
        $prefix = config('sqs.prefix', env('SQS_QUEUE_PREFIX', env('APP_ENV', 'dev')));
        return "{$prefix}-{$baseName}";
    }

    /**
     * Check if queue exists without creating it
     * Uses AWS SQS getQueueUrl API - returns false if queue doesn't exist
     *
     * @param string $queueName Base queue name (without prefix)
     * @return bool True if queue exists, false otherwise
     */
    public function queueExists(string $queueName): bool
    {
        $resolvedQueueName = $this->resolveQueueName($queueName);

        try {
            $this->sqsClient->getQueueUrl(['QueueName' => $resolvedQueueName]);
            return true;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AWS.SimpleQueueService.NonExistentQueue') {
                return false;
            }
            return false;
        }
    }

    /**
     * Get queue URL, create if it doesn't exist
     *
     */
    private function getQueueUrl(string $queueName): array
    {
        try {
            $result = $this->sqsClient->getQueueUrl([
                'QueueName' => $queueName
            ]);
            return [
                'status' => 200,
                'message' => 'Queue exists',
                'queue_url' => $result->get('QueueUrl')
            ];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AWS.SimpleQueueService.NonExistentQueue') {
                return [
                    'status' => 400,
                    'message' => 'Queue does not exist',
                    'queue_url' => null
                ];
            }
            return [
                'status' => 500,
                'message' => $e->getMessage(),
                'queue_url' => null
            ];
        }
    }

    /**
     * Create main queue + DLQ, attach via RedrivePolicy
     */
    private function createQueue(string $queueName): string
    {
        try {
            // Create DLQ first
            $dlqName = "{$queueName}-dlq";

            $dlqResult = $this->sqsClient->createQueue([
                'QueueName' => $dlqName,
                'Attributes' => [
                    'MessageRetentionPeriod' => '1209600', // 14 days
                ],
            ]);

            $dlqUrl = $dlqResult->get('QueueUrl');

            // Get the DLQ ARN to attach it to the main queue
            $dlqArnResult = $this->sqsClient->getQueueAttributes([
                'QueueUrl' => $dlqUrl,
                'AttributeNames' => ['QueueArn'],
            ]);

            $dlqArn = $dlqArnResult->get('Attributes')['QueueArn'];

            // Create main queue with DLQ redrive policy
            $mainQueueResult = $this->sqsClient->createQueue([
                'QueueName' => $queueName,
                'Attributes' => [
                    'VisibilityTimeout' => '30', // 30 seconds visibility timeout
                    'ReceiveMessageWaitTimeSeconds' => '20', // Enable long polling
                    'MessageRetentionPeriod' => '1209600', // 14 days retention (Save Messages for 14 days)
                    'RedrivePolicy' => json_encode([
                        'deadLetterTargetArn' => $dlqArn,
                        'maxReceiveCount' => 5, // After 5 receives, message goes to DLQ
                    ]),
                ],
            ]);

            $queueUrl = $mainQueueResult->get('QueueUrl');

            Log::info('Queue and DLQ created successfully', [
                'queue_name' => $queueName,
                'dlq_name' => $dlqName,
                'queue_url' => $queueUrl,
                'dlq_url' => $dlqUrl,
            ]);

            return $queueUrl;

        } catch (\Throwable $e) {
            Log::error('Error creating queue and DLQ', [
                'queue_name' => $queueName,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("Error creating queue: " . $queueName . " with message" . $e->getMessage());
        }
    }
}

