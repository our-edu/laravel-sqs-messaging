<?php

namespace OurEdu\SqsMessaging\Drivers\RabbitMq;

use Domain\StudentServiceSubscription\Notification\Notification;
use OurEdu\SqsMessaging\Contracts\MessagingDriverInterface;
use Illuminate\Support\Facades\Log;
use OurEdu\SqsMessaging\Enums\DriversEnum;

/**
 * RabbitMQ Messaging Driver
 */
class RabbitMqMessagingDriver implements MessagingDriverInterface
{
    public function publish($event, string $eventClassReference = null)
    {
        $eventClassReference::publishFromInstance($event);
//        RabbitMqPublisherAdapter::publish($event->publishEventKey(), $event->toPublish());
    }

    public function getName(): string
    {
        return DriversEnum::RabbitMQ;
    }

    public function isAvailable(string $eventClassReference = null): bool
    {
        try {
            return !empty($eventClassReference) ?? false;
        } catch (\Throwable $e) {
            Log::warning('RabbitMq driver not available', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

