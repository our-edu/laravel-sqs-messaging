<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Sqs;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class SQSPublisher
{
    private SqsClient $sqsClient;
    private SQSResolver $sqsResolver;

    public function __construct(?SqsClient $sqsClient = null, ?SQSResolver $sqsResolver = null)
    {
        $this->sqsClient = $sqsClient ?? new SqsClient([
            'region' => config('aws.region', env('AWS_DEFAULT_REGION', 'us-east-2')),
            'version' => 'latest',
            'credentials' => [
                'key' => config('aws.key', env('AWS_ACCESS_KEY_ID')),
                'secret' => config('aws.secret', env('AWS_SECRET_ACCESS_KEY')),
            ],
        ]);
        
        $this->sqsResolver = $sqsResolver ?? new SQSResolver($this->sqsClient);
    }

    /**
     * Publish a single message to SQS
     *
     * @throws Throwable
     */
    public function publish(string $queue, string $eventType, array $payload, array $attributes = []): string
    {
        $service = config('app.name', 'payment-service');
        $message = MessageEnvelope::wrap($eventType, $payload, $service);
        
        $messageAttributes = array_merge(
            [
                'EventType' => [
                    'DataType' => 'String',
                    'StringValue' => $eventType,
                ],
            ],
            $this->formatAttributes($attributes)
        );

        $queueUrl = $this->sqsResolver->resolve($queue);
        
        try {
            $result = $this->sqsClient->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode($message),
                'MessageAttributes' => $messageAttributes,
            ]);

            Log::info('SQS message published', [
                'queue' => $queue,
                'queue_url' => $queueUrl,
                'event_type' => $eventType,
                'message_id' => $result->get('MessageId'),
                'idempotency_key' => $message['idempotency_key'],
            ]);

            return $result->get('MessageId');
        } catch (Throwable $e) {
            Log::error('SQS Publish Error', [
                'queue_url' => $queueUrl,
                'event_type' => $eventType,
                'payload' => $payload,
                'attributes' => $attributes,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Publish multiple messages in a batch
     *
     * @throws Throwable
     */
    public function publishBatch(string $queue, array $messages): array
    {
        $queueUrl = $this->sqsResolver->resolve($queue);
        $service = config('app.name', 'payment-service');
        
        $entries = [];
        foreach ($messages as $index => $message) {
            $envelope = MessageEnvelope::wrap(
                $message['eventType'],
                $message['payload'],
                $service
            );
            
            $entries[] = [
                'Id' => (string) $index,
                'MessageBody' => json_encode($envelope),
                'MessageAttributes' => $this->formatAttributes(array_merge(
                    [
                        'EventType' => [
                            'DataType' => 'String',
                            'StringValue' => $message['eventType'],
                        ],
                    ],
                    $message['attributes'] ?? []
                )),
            ];
        }

        try {
            $result = $this->sqsClient->sendMessageBatch([
                'QueueUrl' => $queueUrl,
                'Entries' => $entries,
            ]);

            Log::info('SQS batch messages published', [
                'queue' => $queue,
                'queue_url' => $queueUrl,
                'count' => count($entries),
                'successful' => count($result->get('Successful', [])),
                'failed' => count($result->get('Failed', [])),
            ]);

            return $result->toArray();
        } catch (Throwable $e) {
            Log::error('SQS Batch Publish Error', [
                'queue_url' => $queueUrl,
                'messages' => $messages,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Format attributes for SQS MessageAttributes
     */
    private function formatAttributes(array $attributes): array
    {
        $formatted = [];
        
        foreach ($attributes as $key => $value) {
            $formatted[$key] = [
                'StringValue' => (string) $value,
                'DataType' => 'String',
            ];
        }
        
        return $formatted;
    }
}

