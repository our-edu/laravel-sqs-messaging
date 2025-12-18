<?php

namespace OurEdu\SqsMessaging\Contracts;

/**
 * Messaging Driver Interface
 * 
 * All messaging drivers must implement this interface.
 * This allows adding new drivers (SQS, RabbitMQ, Pusher, etc.) without modifying existing code.
 */
interface MessagingDriverInterface
{
    /**
     * Publish a message
     * 
     * @param object $event Event that implements publishEventKey() and toPublish()
     * @param string $queueName Target queue)
     * @return string|void Message ID or void
     */
    public function publish($event,string $queueName);

    /**
     * Get driver name
     * 
     * @return string Driver identifier (e.g., 'sqs', 'rabbitmq', 'pusher')
     */
    public function getName(): string;

    /**
     * Check if driver is available
     * 
     * @return bool
     */
    public function isAvailable(): bool;
}

