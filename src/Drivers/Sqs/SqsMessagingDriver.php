<?php

namespace OurEdu\SqsMessaging\Drivers\Sqs;

use Illuminate\Support\Facades\Log;
use OurEdu\SqsMessaging\Contracts\MessagingDriverInterface;
use OurEdu\SqsMessaging\Enums\DriversEnum;

/**
 * SQS Messaging Driver
 */
class SqsMessagingDriver implements MessagingDriverInterface
{
    private SQSPublisherAdapter $adapter;

    public function __construct(?SQSPublisherAdapter $adapter = null)
    {
        $this->adapter = $adapter ?? app(SQSPublisherAdapter::class);
    }

    public function publish($event, string $eventClassReference = null): string
    {
        try {
            $queueName = SQSTargetQueueResolver::resolve($event->publishEventKey());
            logOnSlackDataIfExists(messages: 'Publishing to SQS', context: [
                'queue' => $queueName,
                'payload' => $event->toPublish(),
            ]);
            return $this->adapter->publish($event, $queueName);
        }catch (\Exception $exception){
            Log::error('SQS Publish Error', [
                'queue' => isset($queueName) ? $queueName : 'unknown',
                'payload' => method_exists($event, 'toPublish') ? $event->toPublish() : [],
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    public function getName(): string
    {
        return DriversEnum::SQS;
    }

    public function isAvailable(string $eventClassReference = null): bool
    {
        try {
            // Check if SQS adapter can be instantiated
            app(SQSPublisherAdapter::class);
            return true;
        } catch (\Throwable $e) {
            Log::warning('SQS driver not available', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

