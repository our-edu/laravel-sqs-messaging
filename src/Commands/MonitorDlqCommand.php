<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OurEdu\SqsMessaging\Drivers\Sqs\SQSConsumer;
use OurEdu\SqsMessaging\Drivers\Sqs\SQSResolver;

/**
 * Daily monitoring command to check DLQ depth
 * Should be scheduled to run daily
 */
class MonitorDlqCommand extends Command
{
    protected $signature = 'sqs:monitor-dlq {queue? : Specific queue to check (optional, checks all if omitted)}';
    protected $description = 'Monitor Dead Letter Queue depth and alert if high';

    public function handle(SQSResolver $resolver): int
    {
        $queues = $this->argument('queue') 
            ? [$this->argument('queue')]
            : $this->getAllQueues();

        $this->info('Monitoring DLQ depth...');
        $this->newLine();

        $totalDlqDepth = 0;
        $alerts = [];

        foreach ($queues as $queueName) {
            try {
                $dlqName = $queueName . '-dlq';
                $dlqUrl = $resolver->resolve($dlqName);
                $consumer = new SQSConsumer($dlqUrl);
                $depth = $consumer->getQueueDepth();

                $totalDlqDepth += $depth;

                $this->line("Queue: {$queueName}");
                $this->line("DLQ: {$dlqName}");
                $this->line("Depth: {$depth} message(s)");
                $this->line("URL: {$dlqUrl}");
                $this->newLine();

                if ($depth > 10) {
                    $alerts[] = [
                        'queue' => $queueName,
                        'dlq_name' => $dlqName,
                        'depth' => $depth,
                    ];

                    Log::critical('High DLQ depth detected', [
                        'queue' => $queueName,
                        'dlq_name' => $dlqName,
                        'depth' => $depth,
                        'action_required' => 'Investigate failed messages',
                    ]);

                    $this->warn("⚠️  ALERT: High DLQ depth ({$depth}) for {$queueName}");
                }
            } catch (\Throwable $e) {
                $this->error("Error checking DLQ for {$queueName}: " . $e->getMessage());
                Log::error('DLQ Monitoring Error', [
                    'queue' => $queueName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Total DLQ depth across all queues: {$totalDlqDepth}");

        // Alert engineering team if any alerts
        if (!empty($alerts)) {
            $this->notifyEngineering('DLQ Alert', [
                'total_depth' => $totalDlqDepth,
                'alerts' => $alerts,
            ]);

            $this->newLine();
            $this->error("❌ ALERTS TRIGGERED: {$totalDlqDepth} messages in DLQ require investigation");
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("✅ All DLQs are healthy");
        return Command::SUCCESS;
    }

    private function getAllQueues(): array
    {
        $queues = [];
        $config = config('sqs_queues', []);

        foreach ($config as $service => $definitions) {
            if (isset($definitions['default'])) {
                $queues[] = $definitions['default'];
            }

            foreach (($definitions['specific'] ?? []) as $queue) {
                $queues[] = $queue;
            }
        }

        return $queues;
    }

    private function notifyEngineering(string $title, array $context): void
    {
        // Send to Slack
        Log::channel('slack')->critical($title, $context);
    }
}

