<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Drivers\Sqs;

/**
 * Adapter to publish RabbitMQ-compatible events to SQS
 * 
 * This allows you to use existing ShouldPublish events with SQS
 * without modifying the event classes.
 * 
 * Note: This requires the ShouldPublish interface from your RabbitMQ package.
 * If you don't have it, you can remove this file or create a compatible interface.
 */
class SQSPublisherAdapter
{
    private SQSPublisher $sqsPublisher;

    public function __construct(?SQSPublisher $sqsPublisher = null)
    {
        $this->sqsPublisher = $sqsPublisher ?? new SQSPublisher();
    }

    /**
     * Publish a ShouldPublish event to SQS
     * 
     * @param object $event The event to publish (must implement publishEventKey() and toPublish())
     * @param string $queueName Target SQS queue name
     * @return string Message ID
     */
    public function publish($event, string $queueName): string
    {
        $eventType = method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event);
        $payload = method_exists($event, 'toPublish') ? $event->toPublish() : [];
        
        return $this->sqsPublisher->publish($queueName, $eventType, $payload);
    }

    /**
     * Publish multiple events in a batch
     * 
     * @param array $events Array of ['event' => object, 'queue' => string]
     * @return array Array of message IDs
     */
    public function publishBatch(array $events): array
    {
        $messagesByQueue = [];
        
        foreach ($events as $item) {
            $event = $item['event'];
            $queue = $item['queue'];
            
            if (!isset($messagesByQueue[$queue])) {
                $messagesByQueue[$queue] = [];
            }
            
            $eventType = method_exists($event, 'publishEventKey') ? $event->publishEventKey() : get_class($event);
            $payload = method_exists($event, 'toPublish') ? $event->toPublish() : [];
            
            $messagesByQueue[$queue][] = [
                'eventType' => $eventType,
                'payload' => $payload,
            ];
        }
        
        $results = [];
        foreach ($messagesByQueue as $queue => $messages) {
            $messageIds = $this->sqsPublisher->publishBatch($queue, $messages);
            $results = array_merge($results, $messageIds);
        }
        
        return $results;
    }
}

