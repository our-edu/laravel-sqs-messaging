<?php

namespace OurEdu\SqsMessaging;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use OurEdu\SqsMessaging\Sqs\CloudWatchMetricsService;
use OurEdu\SqsMessaging\Sqs\SQSResolver;

class SqsMessagingServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Register CloudWatchMetricsService as singleton
        $this->app->singleton(CloudWatchMetricsService::class, function ($app) {
            return new CloudWatchMetricsService();
        });

        // Merge config files
        $this->mergeConfigFrom(__DIR__ . '/../config/sqs.php', 'sqs');
        $this->mergeConfigFrom(__DIR__ . '/../config/sqs_queues.php', 'sqs_queues');
        $this->mergeConfigFrom(__DIR__ . '/../config/sqs_events.php', 'sqs_events');
        $this->mergeConfigFrom(__DIR__ . '/../config/sqs_target_queues.php', 'sqs_target_queues');
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Publish config files
        $this->publishes([
            __DIR__ . '/../config/sqs.php' => config_path('sqs.php'),
            __DIR__ . '/../config/sqs_queues.php' => config_path('sqs_queues.php'),
            __DIR__ . '/../config/sqs_events.php' => config_path('sqs_events.php'),
            __DIR__ . '/../config/sqs_target_queues.php' => config_path('sqs_target_queues.php'),
        ], 'config');

        // Publish migration
        $this->publishes([
            __DIR__ . '/../database/migrations/create_processed_events_table.php' => database_path('migrations/' . date('Y_m_d_His') . '_create_processed_events_table.php'),
        ], 'migrations');

        // Load commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \OurEdu\SqsMessaging\Commands\SqsConsumeCommand::class,
                \OurEdu\SqsMessaging\Commands\EnsureSqsQueuesCommand::class,
                \OurEdu\SqsMessaging\Commands\InspectDlqCommand::class,
                \OurEdu\SqsMessaging\Commands\MonitorDlqCommand::class,
                \OurEdu\SqsMessaging\Commands\ReplayDlqCommand::class,
                \OurEdu\SqsMessaging\Commands\CleanupProcessedEventsCommand::class,
                \OurEdu\SqsMessaging\Commands\SqsStatusCommand::class,
                \OurEdu\SqsMessaging\Commands\CheckSqsMessagesCommand::class,
                \OurEdu\SqsMessaging\Commands\TestSqsReceiveCommand::class,
                \OurEdu\SqsMessaging\Commands\TestAwsConnectionCommand::class,
            ]);
        }

        // Auto-ensure queues in console mode (optional, can be disabled)
        if (app()->runningInConsole() && !app()->runningUnitTests() && config('sqs.auto_ensure', false)) {
            $this->ensureQueues();
        }
    }

    private function ensureQueues(): void
    {
        try {
            $resolver = app(SQSResolver::class);
            $queues = config('sqs_queues', []);

            foreach ($queues as $service => $definitions) {
                $default = $definitions['default'] ?? null;
                if ($default) {
                    $resolver->resolve($default);
                }

                foreach (($definitions['specific'] ?? []) as $queue) {
                    $resolver->resolve($queue);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to ensure SQS queues on boot', [
                'error' => $e->getMessage()
            ]);
        }
    }
}

