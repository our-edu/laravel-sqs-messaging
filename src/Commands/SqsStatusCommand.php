<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use OurEdu\SqsMessaging\Drivers\Sqs\SQSConsumer;
use OurEdu\SqsMessaging\Drivers\Sqs\SQSResolver;

class SqsStatusCommand extends Command
{
    protected $signature = 'sqs:status {--queue=payment-service-queue : Queue name to check}';
    protected $description = 'Check SQS system status: queue depth, Redis jobs, and workers';

    public function handle(SQSResolver $resolver): int
    {
        $queue = $this->option('queue');
        
        $this->info('📊 SQS System Status');
        $this->line('');
        
        // Check SQS Queue Depth
        try {
            $queueUrl = $resolver->resolve($queue);
            $consumer = new SQSConsumer($queueUrl);
            $depth = $consumer->getQueueDepth();
            
            $this->info("📬 SQS Queue Status:");
            $this->line("   Queue: {$queue}");
            $this->line("   Queue URL: {$queueUrl}");
            $this->line("   Messages waiting: {$depth}");
            
            if ($depth > 0) {
                $this->warn("   ⚠️  {$depth} message(s) waiting to be processed!");
            } else {
                $this->info("   ✅ Queue is empty");
            }
        } catch (\Throwable $e) {
            $this->error("   ❌ Error checking SQS: " . $e->getMessage());
        }
        
        $this->line('');
        
        // Instructions
        $this->info("🔧 To Process Messages:");
        $this->line('');
        $this->line("Supervisor automatically runs SQS Consumer:");
        $this->line("   php artisan sqs:consume {$queue}");
        $this->line('');
        $this->line("To manually test (local development):");
        $this->line("   php artisan sqs:consume {$queue}");
        $this->line('');
        $this->line("3️⃣  Watch Logs:");
        $this->line("   tail -f storage/logs/laravel.log | grep SQS");
        $this->line('');
        
        return Command::SUCCESS;
    }
}

