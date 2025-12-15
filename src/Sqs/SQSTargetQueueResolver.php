<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Sqs;

/**
 * Resolves target SQS queue name for event types
 * 
 * Maps event types to consumer service queues and applies environment prefix.
 */
class SQSTargetQueueResolver
{
    /**
     * Resolve target queue name for an event type
     * 
     * Returns queue name WITHOUT prefix (e.g., 'admission-service-queue')
     * SQSResolver will add the environment prefix automatically.
     * 
     * @param string $eventType The event type (e.g., 'payment:payment:student.subscribe.and.pay')
     * @return string The target queue name WITHOUT prefix (e.g., 'admission-service-queue')
     */
    public static function resolve(string $eventType): string
    {
        $mapping = config('sqs_target_queues', []);
        
        // Get target queue from mapping or use default
        // Return WITHOUT prefix - SQSResolver will add it automatically
        return $mapping[$eventType] ?? $mapping['default'] ?? 'admission-service-queue';
    }
    
    /**
     * Get all target queue mappings
     * 
     * @return array Event type => queue name mapping
     */
    public static function getAllMappings(): array
    {
        return config('sqs_target_queues', []);
    }
}

