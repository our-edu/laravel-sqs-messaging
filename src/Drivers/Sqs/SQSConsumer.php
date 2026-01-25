<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Drivers\Sqs;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class SQSConsumer
{
    private SqsClient $sqsClient;
    private string $queueUrl;

    public function __construct(string $queueUrl)
    {
        $this->sqsClient = new SqsClient([
            'region' => config('sqs.region'),
            'version' => 'latest',
            'credentials' => [
                'key' => config('sqs.access_key_id'),
                'secret' => config('sqs.secret_access_key'),
            ],
        ]);

        $this->queueUrl = $queueUrl;
    }

    /**
     * Receive messages using long polling
     *
     * @throws Throwable
     */
    public function receiveMessages(int $maxMessages = 10, int $waitTimeSeconds = 20): array
    {
        try {
            $result = $this->sqsClient->receiveMessage([
                'QueueUrl' => $this->queueUrl,
                'MaxNumberOfMessages' => $maxMessages,
                'WaitTimeSeconds' => $waitTimeSeconds, // Enable long polling
                'VisibilityTimeout' => 30,
                'MessageAttributeNames' => ['All'],
                'AttributeNames' => ['All'],
            ]);

            return $result->get('Messages') ?? [];
        } catch (Throwable $e) {
            Log::error('SQS Receive Error', [
                'queue_url' => $this->queueUrl,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete message (acknowledge processing)
     *
     * @throws Throwable
     */
    public function deleteMessage(string $receiptHandle): void
    {
        try {
            $this->sqsClient->deleteMessage([
                'QueueUrl' => $this->queueUrl,
                'ReceiptHandle' => $receiptHandle,
            ]);
        } catch (Throwable $e) {
            Log::error('SQS Delete Message Error', [
                'queue_url' => $this->queueUrl,
                'receipt_handle' => $receiptHandle,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Change message visibility timeout (extend processing time)
     *
     * @throws Throwable
     */
    public function changeVisibilityTimeout(string $receiptHandle, int $visibilityTimeout): void
    {
        try {
            $this->sqsClient->changeMessageVisibility([
                'QueueUrl' => $this->queueUrl,
                'ReceiptHandle' => $receiptHandle,
                'VisibilityTimeout' => $visibilityTimeout,
            ]);

            Log::info('Extended visibility timeout', [
                'queue' => $this->queueUrl,
                'new_timeout' => $visibilityTimeout,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to extend visibility timeout', [
                'queue_url' => $this->queueUrl,
                'receipt_handle' => $receiptHandle,
                'visibility_timeout' => $visibilityTimeout,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get approximate number of messages in the queue
     *
     * @throws Throwable
     */
    public function getQueueDepth(): int
    {
        try {
            $result = $this->sqsClient->getQueueAttributes([
                'QueueUrl' => $this->queueUrl,
                'AttributeNames' => ['ApproximateNumberOfMessages'],
            ]);

            return (int)($result->get('Attributes')['ApproximateNumberOfMessages'] ?? 0);
        } catch (Throwable $e) {
            Log::error('SQS Get Queue Depth Error', [
                'queue_url' => $this->queueUrl,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

