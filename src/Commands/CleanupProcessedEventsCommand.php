<?php

namespace OurEdu\SqsMessaging\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily scheduled job to clean up old processed_events records
 * Removes records older than 7 days to keep database size manageable
 */
class CleanupProcessedEventsCommand extends Command
{
    protected $signature = 'sqs:cleanup-processed-events {--days=7 : Number of days to keep records}';
    protected $description = 'Clean up old processed_events records from database';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        try {
            $this->info("Cleaning up processed_events older than {$days} days...");
            $this->info("Cutoff date: {$cutoffDate->toDateTimeString()}");

            // Count records to be deleted
            $count = DB::table('processed_events')
                ->where('processed_at', '<', $cutoffDate)
                ->count();

            if ($count === 0) {
                $this->info("✅ No records to clean up");
                return Command::SUCCESS;
            }

            $this->info("Found {$count} record(s) to delete");

            if (!$this->confirm("Delete {$count} record(s)?", true)) {
                $this->info("Cleanup cancelled");
                return Command::SUCCESS;
            }

            // Delete old records
            $deleted = DB::table('processed_events')
                ->where('processed_at', '<', $cutoffDate)
                ->delete();

            $this->info("✅ Deleted {$deleted} record(s)");

            Log::info('Processed events cleanup completed', [
                'deleted_count' => $deleted,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'days' => $days,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Error cleaning up processed_events: " . $e->getMessage());
            
            Log::error('Processed events cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}

