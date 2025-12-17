<?php

namespace OurEdu\SqsMessaging;

use OurEdu\SqsMessaging\Sqs\SQSPublisher;
use OurEdu\SqsMessaging\Sqs\SQSPublisherAdapter;
use OurEdu\SqsMessaging\Sqs\SQSTargetQueueResolver;
use Illuminate\Support\Facades\Log;

/**
 * Unified Messaging Service
 * 
 * Provides a single interface to publish messages via SQS or RabbitMQ.
 * Switch between drivers using MESSAGING_DRIVER environment variable.
 * 
 * This enables easy rollback from SQS to RabbitMQ if needed.
 */
class MessagingService
{
    private string $driver;
    private ?SQSPublisherAdapter $sqsAdapter = null;
    private $rabbitmqPublisher = null;

    public function __construct()
    {
        $this->driver = config('messaging.driver', env('MESSAGING_DRIVER', 'sqs'));
        
        if ($this->driver === 'sqs') {
            $this->sqsAdapter = app(SQSPublisherAdapter::class);
        } elseif ($this->driver === 'rabbitmq') {
            // Lazy load RabbitMQ publisher only if needed
            if (class_exists(\Support\RabbitMQ\Publisher::class)) {
                $this->rabbitmqPublisher = app(\Support\RabbitMQ\Publisher::class);
            } else {
                Log::warning('RabbitMQ Publisher class not found. Falling back to SQS.');
                $this->driver = 'sqs';
                $this->sqsAdapter = app(SQSPublisherAdapter::class);
            }
        }
    }

    /**
     * Publish a message (works with both SQS and RabbitMQ)
     * 
     * Supports:
     * - Single driver mode (sqs or rabbitmq)
     * - Dual write mode (publish to both)
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
        if ($dualWrite && $this->driver === 'sqs' && $this->rabbitmqPublisher) {
            $sqsResult = null;
            $rabbitmqResult = null;

            // Always publish to SQS
            try {
                $sqsResult = $this->publishToSqs($event, $queueName);
            } catch (\Throwable $e) {
                Log::error('Dual write: SQS publish failed', [
                    'error' => $e->getMessage(),
                    'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                ]);
            }

            // Also publish to RabbitMQ
            try {
                $rabbitmqResult = $this->publishToRabbitMQ($event);
            } catch (\Throwable $e) {
                Log::warning('Dual write: RabbitMQ publish failed', [
                    'error' => $e->getMessage(),
                    'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                ]);
            }

            return $sqsResult ?? $rabbitmqResult;
        }

        // Normal mode: single driver
        if ($this->driver === 'sqs') {
            try {
                return $this->publishToSqs($event, $queueName);
            } catch (\Throwable $e) {
                // Fallback to RabbitMQ if enabled
                if ($fallbackEnabled && $this->rabbitmqPublisher) {
                    Log::warning('SQS publish failed, falling back to RabbitMQ', [
                        'error' => $e->getMessage(),
                        'event' => method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event),
                    ]);
                    return $this->publishToRabbitMQ($event);
                }
                throw $e;
            }
        } else {
            return $this->publishToRabbitMQ($event);
        }
    }

    /**
     * Publish to SQS
     */
    private function publishToSqs($event, ?string $queueName): string
    {
        if (!$queueName) {
            // Auto-resolve queue name from event type
            $eventType = method_exists($event, 'publishEventKey') 
                ? $event->publishEventKey() 
                : get_class($event);
            $queueName = SQSTargetQueueResolver::resolve($eventType);
        }

        return $this->sqsAdapter->publish($event, $queueName);
    }

    /**
     * Publish to RabbitMQ
     */
    private function publishToRabbitMQ($event)
    {
        if (!$this->rabbitmqPublisher) {
            throw new \RuntimeException('RabbitMQ Publisher not available. Check MESSAGING_DRIVER configuration.');
        }

        return $this->rabbitmqPublisher->publish($event);
    }

    /**
     * Get current driver
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
        return $this->driver === 'sqs';
    }

    /**
     * Check if using RabbitMQ
     */
    public function isRabbitMQ(): bool
    {
        return $this->driver === 'rabbitmq';
    }
}

