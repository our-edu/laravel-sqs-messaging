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
        try {
            if ($eventClassReference === null) {
                throw new \RuntimeException("Event class reference is required for RabbitMQ publishing.");
            }
            $eventClassReference::publishFromInstance($event);
            Log::info('RabbitMq message published', [
                'queue' => $event->publishEventKey(),
                'payload' => json_encode($event->toPublish(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

        }catch (\Exception $e){
            Log::error('RabbitMq Publish Error', [
                'queue' => $event->publishEventKey(),
                'payload' => json_encode($event->toPublish(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getName(): string
    {
        return DriversEnum::RabbitMQ;
    }

    public function isAvailable(): bool
    {
      return true;
    }
}

