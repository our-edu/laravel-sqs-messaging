<?php

use Illuminate\Support\Facades\Log;

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
        array        $context = []
    ): void
    {
        if (is_array($messages) and !empty($messages)) {
            foreach ($messages as $message) {
                logWithFallback(channel: 'slackLogData',
                    level: 'error',
                    message: $message,
                    context: $context
                );
            }
        } elseif (is_string($messages)) {
            logWithFallback(channel: 'slackLogData',
                level: 'error',
                message: $messages,
                context: $context
            );
        }
    }
}
