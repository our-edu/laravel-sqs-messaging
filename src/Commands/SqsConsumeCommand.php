<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use OurEdu\SqsMessaging\Sqs\CloudWatchMetricsService;
use OurEdu\SqsMessaging\Sqs\MessageEnvelope;
use OurEdu\SqsMessaging\Sqs\SQSConsumer;
use OurEdu\SqsMessaging\Sqs\SQSResolver;
use PDOException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Http\Client\ConnectionException;
use Predis\Connection\ConnectionException as RedisConnectionException;
use Illuminate\Database\QueryException;

/**
 * Worker command responsible for orchestrating message consumption per service queue.
 * Each command instance (per queue) is managed by Supervisor.
 * 
 * Follows LLD specification with:
 * - Long polling (20s)
 * - Message validation
 * - Idempotency checking
 * - Error classification (validation, transient, permanent)
 * - Visibility timeout extension for long-running events
 * - Exit after each polling cycle (Supervisor restarts process)
 */
class SqsConsumeCommand extends Command
{
    protected $signature = 'sqs:consume {queue : The SQS queue name to consume}';
    protected $description = 'Consume messages from SQS queue (managed by Supervisor)';

    // Exception classes that indicate transient errors (should retry)
    private const TRANSIENT_EXCEPTIONS = [
        ConnectionException::class, // Illuminate HTTP Client
        ConnectException::class, // Guzzle
        PDOException::class,
        QueryException::class,
        RedisConnectionException::class,
        GuzzleException::class,
        ServerException::class,
        TooManyRedirectsException::class,
        \Aws\Exception\AwsException::class, // AWS throttling/rate limiting
    ];

    // Exception classes that indicate permanent errors (should not retry)
    private const PERMANENT_EXCEPTIONS = [
        // Add your custom business exception classes here
        // \Domain\Exceptions\BusinessRuleException::class,
        // \Domain\Exceptions\EntityNotFoundException::class,
        // \Domain\Exceptions\InvalidStateTransitionException::class,
    ];

    public function handle(SQSResolver $resolver): int
    {
        $queue = $this->argument('queue');
        
        try {
            // Resolve Queue URL (creates if doesn't exist)
            $queueUrl = $resolver->resolve($queue);
            $consumer = new SQSConsumer($queueUrl);
            
            $this->info("Polling queue: {$queue}");
            $this->line("Queue URL: {$queueUrl}");
            
            // Poll Messages (long polling - 20s wait)
            $messages = $consumer->receiveMessages(10, 20);
            
            if (empty($messages)) {
                $this->line("No messages found");
                return Command::SUCCESS; // Exit - Supervisor will restart
            }
            
            $this->info("Received " . count($messages) . " message(s)");
            
            // Process each message
            foreach ($messages as $message) {
                $this->processMessage($message, $consumer, $queue);
            }
            
            return Command::SUCCESS; // Exit after processing - Supervisor restarts
        } catch (\Throwable $e) {
            Log::error('SQS Consume Command Error', [
                'queue' => $queue,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->error("Error: " . $e->getMessage());
            
            // Exit with failure - Supervisor will restart
            return Command::FAILURE;
        }
    }

    private function processMessage(array $message, SQSConsumer $consumer, string $queue): void
    {
        $receiptHandle = $message['ReceiptHandle'];
        $messageId = $message['MessageId'] ?? 'unknown';
        $receiveCount = (int) ($message['Attributes']['ApproximateReceiveCount'] ?? 1);
        
        $idempotencyKey = null;
        $eventType = null;
        
        try {
            // Step 1: Decode JSON
            $body = json_decode($message['Body'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Validation Error - Delete immediately
                Log::warning('Invalid JSON, discarding', [
                    'queue' => $queue,
                    'message_id' => $messageId,
                    'error' => json_last_error_msg(),
                ]);
                
                $this->recordMetrics('unknown', 'validation_error');
                $consumer->deleteMessage($receiptHandle);
                return;
            }
            
            // Step 2: Validate MessageEnvelope
            if (!MessageEnvelope::validate($body)) {
                // Validation Error - Delete immediately
                Log::warning('Invalid message envelope, discarding', [
                    'queue' => $queue,
                    'message_id' => $messageId,
                    'body' => $body,
                ]);
                
                $this->recordMetrics('unknown', 'validation_error');
                $consumer->deleteMessage($receiptHandle);
                return;
            }
            
            $eventType = MessageEnvelope::getEventType($body);
            $payload = MessageEnvelope::unwrap($body);
            $idempotencyKey = $body['idempotency_key'];
            
            // Step 3: Idempotency Check
            if ($this->isAlreadyProcessed($idempotencyKey)) {
                Log::info('Duplicate message detected, skipping', [
                    'queue' => $queue,
                    'idempotency_key' => $idempotencyKey,
                    'event_type' => $eventType,
                    'receive_count' => $receiveCount,
                ]);
                
                // Delete duplicate message
                $consumer->deleteMessage($receiptHandle);
                $this->recordMetrics($eventType, 'success');
                return;
            }
            
            // Step 4: Mark as processing (with TTL to handle crashes)
            $this->markAsProcessing($idempotencyKey);
            
            // Step 5: Extend visibility timeout for long-running events
            $longRunningEvents = config('sqs.long_running_events', []);
            if (in_array($eventType, $longRunningEvents)) {
                $consumer->changeVisibilityTimeout($receiptHandle, 120); // 2 minutes
                Log::info('Extended visibility timeout for long-running event', [
                    'event_type' => $eventType,
                    'timeout' => 120,
                ]);
            }
            
            // Step 6: Process event
            $eventMap = config('sqs_events', []);
            
            if (!isset($eventMap[$eventType])) {
                Log::warning('Event type not mapped', [
                    'queue' => $queue,
                    'event_type' => $eventType,
                    'available_events' => array_keys($eventMap),
                ]);
                
                // Not mapped - treat as permanent error (delete)
                $consumer->deleteMessage($receiptHandle);
                $this->recordMetrics($eventType, 'permanent_error');
                return;
            }
            
            // Instantiate and call listener
            $listenerClass = $eventMap[$eventType];
            $listener = app($listenerClass);
            
            if (method_exists($listener, 'handle')) {
                // Call listener with payload
                // Note: If listener expects an Event object, create it from payload
                // For now, pass payload directly
                $listener->handle($payload);
            } else {
                throw new \RuntimeException("Listener {$listenerClass} does not have handle method");
            }
            
            // Step 7: Success - Mark as processed and acknowledge
            $this->markAsProcessed($idempotencyKey, $eventType);
            $consumer->deleteMessage($receiptHandle);
            
            $this->recordMetrics($eventType, 'success');
            
            Log::info('Message processed successfully', [
                'queue' => $queue,
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
            ]);
            
        } catch (\Throwable $e) {
            // Remove processing lock
            if ($idempotencyKey) {
                $this->removeProcessingLock($idempotencyKey);
            }
            
            // Classify and handle error
            if ($this->isTransientError($e)) {
                $this->handleTransientError($e, $eventType, $receiveCount, $queue);
                // Don't delete - let it retry (SQS will handle retry logic)
                $this->recordMetrics($eventType ?? 'unknown', 'transient_error');
                
            } elseif ($this->isPermanentError($e)) {
                $this->handlePermanentError(
                    $e,
                    $receiptHandle,
                    $consumer,
                    $eventType,
                    $idempotencyKey,
                    $queue
                );
                // Delete message (don't retry)
                $consumer->deleteMessage($receiptHandle);
                $this->recordMetrics($eventType ?? 'unknown', 'permanent_error');
                
            } else {
                // Unknown exception - treat as transient (safer)
                Log::error('Unknown exception type, treating as transient', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'queue' => $queue,
                    'event_type' => $eventType,
                ]);
                
                $this->recordMetrics($eventType ?? 'unknown', 'transient_error');
                // Don't delete - let it retry
            }
        }
    }

    private function isAlreadyProcessed(string $idempotencyKey): bool
    {
        // Check Redis first (fast path)
        if (Redis::exists("sqs:processed:{$idempotencyKey}")) {
            return true;
        }
        
        // Fallback to database
        return DB::table('processed_events')
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    private function markAsProcessing(string $idempotencyKey): void
    {
        // Set with 5-minute TTL to handle crashes
        Redis::setex("sqs:processing:{$idempotencyKey}", 300, now()->toIso8601String());
    }

    private function markAsProcessed(string $idempotencyKey, string $eventType): void
    {
        // Remove processing lock
        Redis::del("sqs:processing:{$idempotencyKey}");
        
        // Mark as processed for 7 days (longer than message retention)
        Redis::setex("sqs:processed:{$idempotencyKey}", 86400 * 7, now()->toIso8601String());
        
        // Also write to database for durability (optional but recommended)
        DB::table('processed_events')->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'event_type' => $eventType,
            'service' => config('app.name', 'payment-service'),
            'processed_at' => now(),
        ]);
    }

    private function removeProcessingLock(string $idempotencyKey): void
    {
        Redis::del("sqs:processing:{$idempotencyKey}");
    }

    private function isTransientError(\Throwable $e): bool
    {
        foreach (self::TRANSIENT_EXCEPTIONS as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }
        
        // Check for connection/network errors by message
        $message = strtolower($e->getMessage());
        if (
            str_contains($message, 'connection') ||
            str_contains($message, 'timeout') ||
            str_contains($message, 'temporarily unavailable') ||
            str_contains($message, 'throttl')
        ) {
            return true;
        }
        
        return false;
    }

    private function isPermanentError(\Throwable $e): bool
    {
        foreach (self::PERMANENT_EXCEPTIONS as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }
        
        // Check for database unique constraint violations (already processed)
        if ($e instanceof \Illuminate\Database\QueryException) {
            $code = $e->getCode();
            // MySQL duplicate key error
            if ($code == 23000 || $code == '23000') {
                return true; // Actually success - already processed at DB level
            }
        }
        
        return false;
    }

    private function handleTransientError(\Throwable $e, ?string $eventType, int $receiveCount, string $queue): void
    {
        Log::warning('Transient error, message will retry', [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'event_type' => $eventType ?? 'unknown',
            'receive_count' => $receiveCount,
            'queue' => $queue,
            'max_receive_count' => 5,
        ]);
        
        // Message will become visible again after visibility timeout
        // SQS will automatically retry
        // After 5 failures, it moves to DLQ
    }

    private function handlePermanentError(
        \Throwable $e,
        string $receiptHandle,
        SQSConsumer $consumer,
        ?string $eventType,
        ?string $idempotencyKey,
        string $queue
    ): void {
        Log::error('Permanent error detected', [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'event_type' => $eventType ?? 'unknown',
            'idempotency_key' => $idempotencyKey,
            'queue' => $queue,
            'trace' => $e->getTraceAsString(),
        ]);
        
        // Alert engineering team (via Slack or other channels)
        $this->notifyEngineering('Permanent SQS Error', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'event_type' => $eventType ?? 'unknown',
            'queue' => $queue,
            'idempotency_key' => $idempotencyKey,
        ]);
        
        // Message will be deleted (acknowledged) - no retry
    }

    private function notifyEngineering(string $title, array $context): void
    {
        // Send to Slack channel
        Log::channel('slack')->critical($title, $context);
        
        // You can also send to other channels (email, PagerDuty, etc.)
    }

    private function recordMetrics(string $eventType, string $outcome): void
    {
        $queue = $this->argument('queue');
        
        // Log metrics for observability
        Log::info('SQS Metrics', [
            'event_type' => $eventType,
            'outcome' => $outcome, // 'success', 'validation_error', 'transient_error', 'permanent_error'
            'queue' => $queue,
        ]);
        
        // Send metrics to CloudWatch
        try {
            $metricsService = app(CloudWatchMetricsService::class);
            
            $dimensions = [
                'Queue' => $queue,
                'EventType' => $eventType,
                'Outcome' => $outcome,
            ];
            
            // Increment the main counter
            $metricsService->increment('sqs.messages.processed', 1.0, $dimensions);
            
            // Increment specific outcome counters
            switch ($outcome) {
                case 'success':
                    $metricsService->increment('sqs.messages.success', 1.0, [
                        'Queue' => $queue,
                        'EventType' => $eventType,
                    ]);
                    break;
                case 'validation_error':
                    $metricsService->increment('sqs.validation_errors', 1.0, [
                        'Queue' => $queue,
                    ]);
                    break;
                case 'transient_error':
                    $metricsService->increment('sqs.transient_errors', 1.0, [
                        'Queue' => $queue,
                        'EventType' => $eventType,
                    ]);
                    break;
                case 'permanent_error':
                    $metricsService->increment('sqs.permanent_errors', 1.0, [
                        'Queue' => $queue,
                        'EventType' => $eventType,
                    ]);
                    break;
            }
        } catch (\Throwable $e) {
            // Don't let metrics failure break message processing
            Log::warning('Failed to send CloudWatch metrics', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
                'outcome' => $outcome,
            ]);
        }
    }
}

