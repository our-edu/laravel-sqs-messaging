<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use OurEdu\SqsMessaging\Drivers\Sqs\SQSConsumer;
use OurEdu\SqsMessaging\Drivers\Sqs\SQSResolver;

class CheckSqsMessagesCommand extends Command
{
    protected $signature = 'sqs:check {--queue=payment-service-queue : Queue name to check}';
    protected $description = 'Check messages in SQS queue and queue depth';

    public function handle(SQSResolver $resolver): int
    {
        $queue = $this->option('queue');
        
        try {
            $this->info("Checking queue: {$queue}");
            $this->line('');
            
            $queueUrl = $resolver->resolve($queue);
            $consumer = new SQSConsumer($queueUrl);
            
            // Get queue depth
            $depth = $consumer->getQueueDepth();
            $this->info("Queue URL: {$queueUrl}");
            $this->info("Queue Depth: {$depth} message(s)");
            $this->line('');
            
            if ($depth > 0) {
                $this->warn("⚠️  There are {$depth} message(s) waiting to be processed!");
                $this->line('');
                $this->line("To process them:");
                $this->line("  Supervisor automatically runs: php artisan sqs:consume {$queue}");
                $this->line("");
                $this->line("  For manual testing:");
                $this->line("     php artisan sqs:consume {$queue}");
            } else {
                $this->info("✅ Queue is empty - all messages have been processed");
            }
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("❌ Error: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

