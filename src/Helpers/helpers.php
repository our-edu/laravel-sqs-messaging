<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;

if (!function_exists('logWithFallback')) {
    function logWithFallback(
        string $channel,
        string $level,
        string $message,
        array  $context = []
    ): void
    {
        try {
            if (array_key_exists($channel, config('logging.channels', []))) {
                Log::channel($channel)->{$level}($message, $context);
            } else {
                Log::{$level}($message, $context);
            }
        } catch (\Throwable $e) {
            Log::{$level}($message, $context);
        }
    }
}
if (!function_exists('logOnSlackDataIfExists')) {
    function logOnSlackDataIfExists(
        string|array $messages,
        ?Command $command = null,
        array $context = []
    ): void {
        if (empty($messages)) {
            return;
        }
        $messages = is_array($messages) ? $messages : [$messages];
        $shouldLogOnSlack = config('messaging.logging_on_slack') || $command === null;
        foreach ($messages as $message) {
            if ($shouldLogOnSlack) {
                logWithFallback(
                    channel: 'slackLogData',
                    level: 'error',
                    message: $message,
                    context: $context
                );
            } else {
                $command->error(sprintf(
                    '[%s] %s',
                    now()->format('Y-m-d H:i:s'),
                    $message
                ));
            }
        }
    }
}