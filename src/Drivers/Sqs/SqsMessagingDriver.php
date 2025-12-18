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

    public function publish($event, string $queueName): string
    {
        if (!$queueName) {
            // Auto-resolve queue name from event type
            $eventType = method_exists($event, 'publishEventKey') 
                ? $event->publishEventKey() 
                : get_class($event);
            $queueName = SQSTargetQueueResolver::resolve($eventType);
        }

        return $this->adapter->publish($event, $queueName);
    }

    public function getName(): string
    {
        return DriversEnum::SQS;
    }

    public function isAvailable(): bool
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

