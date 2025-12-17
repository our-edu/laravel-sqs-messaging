<?php

namespace OurEdu\SqsMessaging\Drivers;

use OurEdu\SqsMessaging\Contracts\MessagingDriverInterface;
use Illuminate\Support\Facades\Log;
use OurEdu\SqsMessaging\Enums\DriversEnum;

/**
 * RabbitMQ Messaging Driver
 */
class RabbitMqMessagingDriver implements MessagingDriverInterface
{
    private $publisher = null;

    public function __construct()
    {
        // Lazy load RabbitMQ publisher only if needed
        if (class_exists(\Support\RabbitMQ\Publisher::class)) {
            try {
                $this->publisher = app(\Support\RabbitMQ\Publisher::class);
            } catch (\Throwable $e) {
                Log::warning('RabbitMQ Publisher could not be instantiated', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    public function publish($event, ?string $queueName = null)
    {
        if (!$this->publisher) {
            throw new \RuntimeException('RabbitMQ Publisher not available. Check MESSAGING_DRIVER configuration.');
        }

        // RabbitMQ doesn't need queue name - it uses event key
        return $this->publisher->publish($event);
    }

    public function getName(): string
    {
        return DriversEnum::RabbitMQ;
    }

    public function isAvailable(): bool
    {
        if (!class_exists(\Support\RabbitMQ\Publisher::class)) {
            return false;
        }

        try {
            app(\Support\RabbitMQ\Publisher::class);
            return true;
        } catch (\Throwable $e) {
            Log::warning('RabbitMQ driver not available', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

