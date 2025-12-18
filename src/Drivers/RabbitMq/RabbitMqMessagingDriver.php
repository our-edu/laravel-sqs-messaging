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
    public function publish($event, string $queueName)
    {
        RabbitMqPublisherAdapter::publish($queueName, $event);
    }

    public function getName(): string
    {
        return DriversEnum::RabbitMQ;
    }

    public function isAvailable(): bool
    {
        try {
            app(RabbitMqPublisherAdapter::class);
            return true;
        } catch (\Throwable $e) {
            Log::warning('RabbitMq driver not available', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

