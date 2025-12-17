<?php

namespace OurEdu\SqsMessaging;

use OurEdu\SqsMessaging\Contracts\MessagingDriverInterface;
use OurEdu\SqsMessaging\Drivers\RabbitMqMessagingDriver;
use OurEdu\SqsMessaging\Drivers\SqsMessagingDriver;
use Illuminate\Support\Facades\Log;
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
     * @return MessagingDriverInterface
     * @throws \RuntimeException
     */
    private function getDriverInstance(string $driverName): MessagingDriverInterface
    {
        if (!isset($this->drivers[$driverName])) {
            throw new \RuntimeException("Messaging driver '{$driverName}' is not registered.");
        }

        $driver = $this->drivers[$driverName];

        if (!$driver->isAvailable()) {
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
     * @param string|null $queueName Target queue (required for SQS, optional for RabbitMQ)
     * @return string|void Message ID (SQS) or void (RabbitMQ)
     */
    public function publish($event, ?string $queueName = null)
    {
        $dualWrite = config('messaging.dual_write', false);
        $fallbackEnabled = config('messaging.fallback_to_rabbitmq', false);

        // Dual write mode: publish to both SQS and RabbitMQ
        if ($dualWrite && $this->driver === DriversEnum::SQS && isset($this->drivers[DriversEnum::RabbitMQ])) {
            $sqsResult = null;
            $rabbitmqResult = null;

            // Always publish to SQS
            try {
                $sqsDriver = $this->getDriverInstance(DriversEnum::SQS);
                $sqsResult = $sqsDriver->publish($event, $queueName);
            } catch (\Throwable $e) {
                Log::error('Dual write: SQS publish failed', [
                    'error' => $e->getMessage(),
                    'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                ]);
            }

            // Also publish to RabbitMQ
            try {
                $rabbitmqDriver = $this->getDriverInstance(DriversEnum::RabbitMQ);
                $rabbitmqResult = $rabbitmqDriver->publish($event, $queueName);
            } catch (\Throwable $e) {
                Log::warning('Dual write: RabbitMQ publish failed', [
                    'error' => $e->getMessage(),
                    'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                ]);
            }

            return $sqsResult ?? $rabbitmqResult;
        }

        // Normal mode: single driver
        try {
            return $this->activeDriver->publish($event, $queueName);
        } catch (\Throwable $e) {
            // Fallback to RabbitMQ if enabled
            if ($fallbackEnabled && $this->driver !== DriversEnum::RabbitMQ && isset($this->drivers[DriversEnum::RabbitMQ])) {
                Log::warning('Primary driver failed, falling back to RabbitMQ', [
                    'error' => $e->getMessage(),
                    'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                ]);
                
                $rabbitmqDriver = $this->getDriverInstance(DriversEnum::RabbitMQ);
                return $rabbitmqDriver->publish($event, $queueName);
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
