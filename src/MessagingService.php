<?php

namespace OurEdu\SqsMessaging;

use Illuminate\Support\Facades\Log;
use OurEdu\SqsMessaging\Contracts\MessagingDriverInterface;
use OurEdu\SqsMessaging\Drivers\RabbitMq\RabbitMqMessagingDriver;
use OurEdu\SqsMessaging\Drivers\Sqs\SqsMessagingDriver;
use OurEdu\SqsMessaging\Enums\DriversEnum;

/**
 * Unified Messaging Service
 *
 * Provides a single interface to publish messages via multiple drivers (SQS, RabbitMQ, Pusher, etc.).
 * Uses Strategy Pattern to allow adding new drivers without modifying existing code.
 *
 * Follows SOLID principles:
 * - Open/Closed: Open for extension (new drivers), closed for modification
 * - Dependency Inversion: Depends on MessagingDriverInterface, not concrete implementations
 */
class MessagingService
{
    private string $driver;
    private MessagingDriverInterface $activeDriver;
    private array $drivers = [];

    public function __construct()
    {
        $this->driver = config('messaging.driver', env('MESSAGING_DRIVER', DriversEnum::SQS));

        // Register available drivers
        $this->registerDrivers();

        // Set active driver
        $this->activeDriver = $this->getDriverInstance($this->driver);
    }

    /**
     * Register all available messaging drivers
     *
     * To add a new driver:
     * 1. Create a class implementing MessagingDriverInterface
     * 2. Add it to this method
     * 3. No other code changes needed!
     */
    private function registerDrivers(): void
    {
        $this->drivers = [
            DriversEnum::SQS => new SqsMessagingDriver(),
            DriversEnum::RabbitMQ => new RabbitMqMessagingDriver(),
            // Add new drivers here:
            // 'pusher' => new PusherMessagingDriver(),
            // 'redis' => new RedisMessagingDriver(),
            // etc.
        ];
    }

    /**
     * Get driver instance by name
     *
     * @param string $driverName
     * @param string|null $eventClassReference
     * @return MessagingDriverInterface
     * @throws \RuntimeException
     */
    private function getDriverInstance(string $driverName, ?string $eventClassReference = null): MessagingDriverInterface
    {
        if (!isset($this->drivers[$driverName])) {
            throw new \RuntimeException("Messaging driver '{$driverName}' is not registered.");
        }

        $driver = $this->drivers[$driverName];

        if (!$driver->isAvailable($eventClassReference)) {
            // Fallback to SQS if configured driver is not available
            if ($driverName !== DriversEnum::SQS && isset($this->drivers[DriversEnum::SQS])) {
                Log::warning("Driver '{$driverName}' not available, falling back to SQS");
                return $this->drivers[DriversEnum::SQS];
            }

            throw new \RuntimeException("Messaging driver '{$driverName}' is not available.");
        }

        return $driver;
    }

    /**
     * Publish a message (works with all registered drivers)
     *
     * Supports:
     * - Single driver mode (sqs, rabbitmq, pusher, etc.)
     * - Dual write mode (publish to both SQS and RabbitMQ)
     * - Fallback mode (fallback to RabbitMQ if SQS fails)
     *
     * @param object $event Event that implements publishEventKey() and toPublish()
     * @return string|void Message ID (SQS) or void (RabbitMQ)
     */
    public function publish($event, $eventClassReference)
    {
        logOnSlackDataIfExists(messages: 'start publish message in MessagingService',
            context: [
                'queue name' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                'payload' => method_exists($event, 'toPublish') ? $event->toPublish() : [],
            ]);
        $dualWrite = config('messaging.dual_write', false);
        $fallbackEnabled = config('messaging.fallback_to_rabbitmq', false);
        // Dual write mode: publish to both SQS and RabbitMQ
        if ($dualWrite && $this->driver === DriversEnum::SQS && isset($this->drivers[DriversEnum::RabbitMQ])) {
            logOnSlackDataIfExists('dual write is enabled in MessagingService');
            $sqsResult = null;
            $rabbitmqResult = null;

            // Always publish to SQS
            try {
                $sqsDriver = $this->getDriverInstance(DriversEnum::SQS, $eventClassReference);
                $sqsResult = $sqsDriver->publish($event);
            } catch (\Throwable $e) {
                Log::error('Dual write: SQS publish failed', [
                    'error' => $e->getMessage(),
                    'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                ]);
            }

            // Also publish to RabbitMQ
            try {
                $rabbitmqDriver = $this->getDriverInstance(DriversEnum::RabbitMQ, $eventClassReference);
                $rabbitmqResult = $rabbitmqDriver->publish($event, $eventClassReference);
            } catch (\Throwable $e) {
                Log::warning('Dual write: RabbitMQ publish failed', [
                    'error' => $e->getMessage(),
                    'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                ]);
            }

            return $sqsResult ?? $rabbitmqResult;
        }

        // Normal mode: single driver
        // If fallback enabled and SQS driver, check queue existence first to prevent auto-creation
        if ($fallbackEnabled && $this->driver === DriversEnum::SQS && isset($this->drivers[DriversEnum::RabbitMQ])) {
            logOnSlackDataIfExists(messages: 'fallback to RabbitMQ is enabled in MessagingService');
            $eventType = method_exists($event, 'publishEventKey') ? $event->publishEventKey() : null;

            if ($eventType) {
                // Resolve queue name from event type (same as SQS driver does)
                $queueName = \OurEdu\SqsMessaging\Drivers\Sqs\SQSTargetQueueResolver::resolve($eventType);
                $sqsResolver = app(\OurEdu\SqsMessaging\Drivers\Sqs\SQSResolver::class);

                // If queue doesn't exist, skip SQS and send to RabbitMQ only
                if (!$sqsResolver->queueExists($queueName)) {
                    $rabbitmqDriver = $this->getDriverInstance(DriversEnum::RabbitMQ, $eventClassReference);
                    return $rabbitmqDriver->publish($event, $eventClassReference);
                }
            }
        }

        try {
            return $this->activeDriver->publish($event);
        } catch (\Throwable $e) {
            // Fallback to RabbitMQ if enabled
            if ($fallbackEnabled && $this->driver !== DriversEnum::RabbitMQ && isset($this->drivers[DriversEnum::RabbitMQ])) {
                $rabbitmqDriver = $this->getDriverInstance(DriversEnum::RabbitMQ, $eventClassReference);
                return $rabbitmqDriver->publish($event, $eventClassReference);
            }
            throw $e;
        }
    }

    /**
     * Get current driver name
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Check if using SQS
     */
    public function isSqs(): bool
    {
        return $this->driver === DriversEnum::SQS;
    }

    /**
     * Check if using RabbitMQ
     */
    public function isRabbitMQ(): bool
    {
        return $this->driver === DriversEnum::RabbitMQ;
    }

    /**
     * Get all registered drivers
     *
     * @return array<string, MessagingDriverInterface>
     */
    public function getAvailableDrivers(): array
    {
        return array_filter($this->drivers, function ($driver) {
            return $driver->isAvailable();
        });
    }

    /**
     * Register a custom driver
     *
     * Allows runtime registration of new drivers (e.g., from service providers)
     *
     * @param string $name Driver name
     * @param MessagingDriverInterface $driver Driver instance
     */
    public function registerDriver(string $name, MessagingDriverInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }
}
