<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use OurEdu\SqsMessaging\Sqs\SQSPublisher;
use OurEdu\SqsMessaging\Sqs\MessageEnvelope;

class TestSqsReceiveCommand extends Command
{
    protected $signature = 'sqs:test:receive 
                            {queue=payment-service-queue : The SQS queue name to test}
                            {--event=admission:assign.subscription.services : The event type to send}
                            {--send : Send a test message before testing receive}';

    protected $description = 'Test receiving messages from SQS queue';

    public function handle(): int
    {
        $queueName = $this->argument('queue');
        $eventType = $this->option('event');

        $this->info("🧪 Testing SQS Receive for queue: {$queueName}");
        $this->newLine();

        // Step 1: Check if event type is mapped
        $this->info("📋 Step 1: Checking event type mapping...");
        $eventMap = config('sqs_events', []);
        
        if (!isset($eventMap[$eventType])) {
            $this->error("❌ Event type '{$eventType}' is not mapped in config/sqs_events.php");
            $this->warn("Available event types:");
            foreach (array_keys($eventMap) as $type) {
                $this->line("  - {$type}");
            }
            return Command::FAILURE;
        }
        
        $listenerClass = $eventMap[$eventType];
        $this->info("✅ Event type mapped to: {$listenerClass}");

        // Step 2: Send test message (optional)
        if ($this->option('send')) {
            $this->newLine();
            $this->info("📤 Step 2: Sending test message...");
            
            try {
                $publisher = new SQSPublisher();
                $testPayload = [
                    'test' => true,
                    'timestamp' => now()->toIso8601String(),
                    'student_uuid' => 'test-uuid-' . uniqid(),
                ];
                
                $messageId = $publisher->publish($queueName, $eventType, $testPayload);
                $this->info("✅ Test message sent successfully!");
                $this->line("   Message ID: {$messageId}");
                $this->line("   Queue: {$queueName}");
                $this->line("   Event Type: {$eventType}");
            } catch (\Throwable $e) {
                $this->error("❌ Failed to send test message: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Step 3: Instructions
        $this->newLine();
        $this->info("📥 Step 3: Testing receive...");
        $this->line("To test receiving messages, you need to:");
        $this->newLine();
        
        $this->line("1. Start SQS Consumer (Supervisor handles this automatically):");
        $this->comment("   php artisan sqs:consume {$queueName}");
        $this->comment("   (Or wait for Supervisor to start it automatically)");
        $this->newLine();
        
        $this->line("3. Watch logs:");
        $this->comment("   tail -f storage/logs/laravel.log | grep SQS");
        $this->newLine();

        // Step 4: Show current queue status
        $this->info("📊 Step 4: Queue status...");
        try {
            $statusCommand = $this->call('sqs:check', ['--queue' => $queueName]);
        } catch (\Throwable $e) {
            $this->warn("Could not check queue status: " . $e->getMessage());
        }

        $this->newLine();
        $this->info("✅ Test setup complete!");
        $this->line("Make sure workers are running to process messages.");

        return Command::SUCCESS;
    }
}

