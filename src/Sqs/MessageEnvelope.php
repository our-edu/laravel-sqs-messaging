<?php

declare(strict_types=1);

namespace OurEdu\SqsMessaging\Sqs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessageEnvelope
{
    /**
     * Wrap payload with standard envelope including idempotency key
     */
    public static function wrap(string $eventType, array $payload, string $service): array
    {
        return [
            'event_type' => $eventType,
            'service' => $service,
            'payload' => $payload,
            'idempotency_key' => self::generateIdempotencyKey($eventType, $payload),
            'trace_id' => Str::uuid()->toString(),
            'timestamp' => now()->toIso8601String(),
            'version' => '1.0',
        ];
    }

    /**
     * Unwrap envelope to get payload
     */
    public static function unwrap(array $envelope): array
    {
        return $envelope['payload'] ?? [];
    }

    /**
     * Get event type from envelope
     */
    public static function getEventType(array $envelope): ?string
    {
        return $envelope['event_type'] ?? null;
    }

    /**
     * Get trace ID from envelope
     */
    public static function getTraceId(?array $envelope = null): string
    {
        if ($envelope && isset($envelope['trace_id'])) {
            return $envelope['trace_id'];
        }
        return (string) Str::uuid();
    }

    /**
     * Validate envelope structure
     */
    public static function validate(array $envelope): bool
    {
        $requiredKeys = ['event_type', 'payload', 'timestamp', 'service', 'version', 'idempotency_key'];
        
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $envelope)) {
                Log::error("MessageEnvelope validation failed: missing key '{$key}'", [
                    'envelope' => $envelope
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Generate idempotency key based on event type and payload
     */
    private static function generateIdempotencyKey(string $eventType, array $payload): string
    {
        $filteredPayload = self::removeTemporaryFields($payload);
        $sortedPayload = self::ksortRecursive($filteredPayload);
        $payloadString = json_encode($sortedPayload);
        return hash('sha256', $eventType . '|' . $payloadString);
    }

    private static function removeTemporaryFields(array $payload): array
    {
        $temporaryFields = [
            'timestamp',
            'created_at',
            'updated_at',
            'deleted_at',
            'trace_id',
        ];

        $filtered = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $temporaryFields, true)) {
                continue;
            }
            
            if (is_array($value)) {
                $filtered[$key] = self::removeTemporaryFields($value);
            } else {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }

    private static function ksortRecursive(array $array): array
    {
        ksort($array);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::ksortRecursive($value);
            }
        }
        return $array;
    }
}

