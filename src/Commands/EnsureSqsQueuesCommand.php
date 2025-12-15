<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use OurEdu\SqsMessaging\Sqs\SQSResolver;

class EnsureSqsQueuesCommand extends Command
{
    protected $signature = 'sqs:ensure';
    protected $description = 'Ensure all SQS queues and DLQs are created (recommended for CI/CD)';

    public function handle(SQSResolver $resolver): int
    {
        $this->info('🔍 Ensuring SQS queues exist...');
        $this->line('');

        $queues = config('sqs_queues', []);
        $total = 0;
        $errors = 0;

        foreach ($queues as $service => $definitions) {
            $this->info("📋 Service: {$service}");

            // Default queue
            $default = $definitions['default'] ?? null;
            if ($default) {
                if ($this->ensureQueue($resolver, $default)) {
                    $total++;
                } else {
                    $errors++;
                }
            }

            // Specific queues
            foreach (($definitions['specific'] ?? []) as $queueName => $queue) {
                $queueToCheck = is_string($queue) ? $queue : $queueName;
                if ($this->ensureQueue($resolver, $queueToCheck)) {
                    $total++;
                } else {
                    $errors++;
                }
            }

            $this->line('');
        }

        // Summary
        $this->info("✅ Completed: {$total} queue(s) ensured");
        if ($errors > 0) {
            $this->error("❌ Errors: {$errors}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function ensureQueue(SQSResolver $resolver, string $queueName): bool
    {
        try {
            // resolve() will create queue if doesn't exist (lazy creation)
            $queueUrl = $resolver->resolve($queueName);
            
            $this->line("  ✅ {$queueName}");
            return true;
        } catch (\Throwable $e) {
            $this->error("  ❌ {$queueName}: {$e->getMessage()}");
            return false;
        }
    }
}

