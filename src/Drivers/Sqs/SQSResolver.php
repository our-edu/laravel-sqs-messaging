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
        $this->sqsClient = $sqsClient ?? new SqsClient([
            'region' => config('aws.region', env('AWS_DEFAULT_REGION', 'us-east-2')),
            'version' => 'latest',
            'credentials' => [
                'key' => config('aws.key', env('AWS_ACCESS_KEY_ID')),
                'secret' => config('aws.secret', env('AWS_SECRET_ACCESS_KEY')),
            ],
        ]);
    }

    /**
     * Resolve queue URL with caching (30 days)
     * Creates queue and DLQ if they don't exist
     */
    public function resolve(string $queueName): string
    {
        // Apply environment prefix
        $resolvedQueueName = $this->resolveQueueName($queueName);
        
        $cacheKey = "sqs_queue_url_{$resolvedQueueName}";
        
        return Cache::remember($cacheKey, now()->addDays(30), function () use ($resolvedQueueName) {
            return $this->getQueueUrl($resolvedQueueName);
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
     * Get queue URL, create if it doesn't exist
     *
     * @throws AwsException
     */
    private function getQueueUrl(string $queueName): string
    {
        try {
            $result = $this->sqsClient->getQueueUrl([
                'QueueName' => $queueName
            ]);
            
            return $result->get('QueueUrl');
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AWS.SimpleQueueService.NonExistentQueue') {
                Log::info('Queue does not exist, creating queue and DLQ', [
                    'queue_name' => $queueName
                ]);
                return $this->createQueue($queueName);
            }
            
            throw $e;
        }
    }

    /**
     * Create main queue + DLQ, attach via RedrivePolicy
     */
    private function createQueue(string $queueName): string
    {
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
    }
}

