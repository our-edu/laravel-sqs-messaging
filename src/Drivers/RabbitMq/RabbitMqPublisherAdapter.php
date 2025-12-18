<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Drivers\RabbitMq;

use Support\RabbitMQ\Publishable;
use Support\RabbitMQ\ShouldPublish;

/**
 * Adapter to publish RabbitMQ-compatible events to SQS
 *
 * This allows you to use existing ShouldPublish events with SQS
 * without modifying the event classes.
 *
 * Note: This requires the ShouldPublish interface from your RabbitMQ package.
 * If you don't have it, you can remove this file or create a compatible interface.
 */
class RabbitMqPublisherAdapter implements ShouldPublish
{
    use Publishable;

    private array|null $payload;
    private string|null $queueName;

    public function __construct(?string $queueName = null, ?array $payload = null)
    {
        $this->payload = $payload;
        $this->queueName = $queueName;
    }

    public function publishEventKey(): string
    {
        return $this->queueName;
    }

    public function toPublish(): array
    {
        return $this->payload;
    }
}