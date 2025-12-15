<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OurEdu\SqsMessaging\Sqs\SQSConsumer;
use OurEdu\SqsMessaging\Sqs\SQSPublisher;
use OurEdu\SqsMessaging\Sqs\SQSResolver;

/**
 * Command to replay DLQ messages back to main queue
 * After fixing underlying issues, use this to reprocess failed messages
 */
class ReplayDlqCommand extends Command
{
    protected $signature = 'sqs:replay-dlq {queue : Queue name (DLQ will be {queue}-dlq)} {--limit=10 : Number of messages to replay}';
    protected $description = 'Replay messages from Dead Letter Queue back to main queue';

    public function handle(SQSResolver $resolver, SQSPublisher $publisher): int
    {
        $queueName = $this->argument('queue');
        $limit = (int) $this->option('limit');
        $dlqName = $queueName . '-dlq';

        try {
            $this->info("Replaying messages from DLQ: {$dlqName}");
            $this->info("Target queue: {$queueName}");
            $this->newLine();

            // Resolve DLQ URL
            $dlqUrl = $resolver->resolve($dlqName);
            $consumer = new SQSConsumer($dlqUrl);

            // Receive messages from DLQ (no wait)
            $messages = $consumer->receiveMessages($limit, 0);

            if (empty($messages)) {
                $this->info("✅ No messages in DLQ to replay");
                return Command::SUCCESS;
            }

            $this->info("Found " . count($messages) . " message(s) in DLQ");
            $this->newLine();

            if (!$this->confirm("Replay " . count($messages) . " message(s) to {$queueName}?", true)) {
                $this->info("Replay cancelled");
                return Command::SUCCESS;
            }

            $replayed = 0;
            $failed = 0;

            foreach ($messages as $message) {
                try {
                    $body = json_decode($message['Body'], true);

                    if (!$body) {
                        $this->warn("⚠️  Skipping message with invalid JSON: " . ($message['MessageId'] ?? 'unknown'));
                        // Delete invalid message from DLQ
                        $consumer->deleteMessage($message['ReceiptHandle']);
                        $failed++;
                        continue;
                    }

                    $eventType = $body['event_type'] ?? 'unknown';
                    $payload = $body['payload'] ?? [];

                    // Republish to main queue
                    $publisher->publish($queueName, $eventType, $payload);

                    // Remove from DLQ
                    $consumer->deleteMessage($message['ReceiptHandle']);

                    $replayed++;
                    $this->info("✅ Replayed: {$eventType} (Message ID: " . ($message['MessageId'] ?? 'N/A') . ")");

                    Log::info('DLQ message replayed', [
                        'dlq' => $dlqName,
                        'queue' => $queueName,
                        'event_type' => $eventType,
                        'message_id' => $message['MessageId'] ?? null,
                        'idempotency_key' => $body['idempotency_key'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("❌ Failed to replay message: " . $e->getMessage());
                    
                    Log::error('DLQ replay error', [
                        'dlq' => $dlqName,
                        'queue' => $queueName,
                        'message_id' => $message['MessageId'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->newLine();
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("Replay Summary:");
            $this->info("  ✅ Replayed: {$replayed}");
            $this->info("  ❌ Failed: {$failed}");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Error replaying DLQ: " . $e->getMessage());
            Log::error('DLQ Replay Command Error', [
                'dlq' => $dlqName,
                'queue' => $queueName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}

