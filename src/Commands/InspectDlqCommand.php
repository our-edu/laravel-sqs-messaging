<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use OurEdu\SqsMessaging\Sqs\SQSConsumer;
use OurEdu\SqsMessaging\Sqs\SQSResolver;

/**
 * Command to inspect DLQ messages for investigation
 */
class InspectDlqCommand extends Command
{
    protected $signature = 'sqs:inspect-dlq {queue : Queue name (DLQ will be {queue}-dlq)} {--limit=10 : Number of messages to inspect}';
    protected $description = 'Inspect messages in Dead Letter Queue for investigation';

    public function handle(SQSResolver $resolver): int
    {
        $queueName = $this->argument('queue');
        $limit = (int) $this->option('limit');
        $dlqName = $queueName . '-dlq';

        try {
            $this->info("Inspecting DLQ: {$dlqName}");
            $this->newLine();

            $dlqUrl = $resolver->resolve($dlqName);
            $consumer = new SQSConsumer($dlqUrl);

            // Receive messages without waiting (WaitTimeSeconds = 0)
            $messages = $consumer->receiveMessages($limit, 0);

            if (empty($messages)) {
                $this->info("✅ No messages in DLQ");
                return Command::SUCCESS;
            }

            $this->info("Found " . count($messages) . " message(s) in DLQ");
            $this->newLine();

            foreach ($messages as $index => $message) {
                $body = json_decode($message['Body'], true);

                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->line("Message #" . ($index + 1));
                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->line("Message ID: " . ($message['MessageId'] ?? 'N/A'));
                $this->line("Receipt Handle: " . substr($message['ReceiptHandle'] ?? 'N/A', 0, 50) . '...');
                $this->line("Receive Count: " . ($message['Attributes']['ApproximateReceiveCount'] ?? 'N/A'));
                $this->line("Sent Timestamp: " . (isset($message['Attributes']['SentTimestamp']) 
                    ? date('Y-m-d H:i:s', (int)($message['Attributes']['SentTimestamp'] / 1000))
                    : 'N/A'));
                $this->newLine();

                if ($body) {
                    $this->line("Event Type: " . ($body['event_type'] ?? 'unknown'));
                    $this->line("Service: " . ($body['service'] ?? 'unknown'));
                    $this->line("Timestamp: " . ($body['timestamp'] ?? 'unknown'));
                    $this->line("Idempotency Key: " . ($body['idempotency_key'] ?? 'N/A'));
                    $this->line("Trace ID: " . ($body['trace_id'] ?? 'N/A'));
                    $this->line("Version: " . ($body['version'] ?? 'N/A'));
                    $this->newLine();

                    $this->line("Payload:");
                    $this->line(json_encode($body['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    $this->warn("Could not decode message body");
                    $this->line("Raw Body: " . ($message['Body'] ?? 'N/A'));
                }

                $this->newLine();
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Error inspecting DLQ: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

