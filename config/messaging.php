<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Messaging Driver
    |--------------------------------------------------------------------------
    |
    | Choose which messaging system to use:
    | - 'sqs'      : AWS SQS (recommended)
    | - 'rabbitmq' : RabbitMQ (legacy, for rollback)
    |
    | You can switch drivers via environment variable:
    | MESSAGING_DRIVER=sqs
    | MESSAGING_DRIVER=rabbitmq
    |
    */

    'driver' => env('MESSAGING_DRIVER', 'sqs'),

    /*
    |--------------------------------------------------------------------------
    | Dual Write Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, messages are sent to BOTH SQS and RabbitMQ.
    | Useful for gradual migration and testing.
    |
    | WARNING: This will send duplicate messages! Only use during migration.
    |
    */

    'dual_write' => env('MESSAGING_DUAL_WRITE', false),

    /*
    |--------------------------------------------------------------------------
    | Fallback Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, if SQS fails, automatically fallback to RabbitMQ.
    | Useful for gradual migration with safety net.
    |
    */

    'fallback_to_rabbitmq' => env('MESSAGING_FALLBACK_TO_RABBITMQ', false),

    /*

      |--------------------------------------------------------------------------
      | Logging on Slack
      |--------------------------------------------------------------------------
      |
      | When enabled, messaging events and errors are logged to Slack channel.
     * */
    'logging_on_slack' => env('MESSAGING_LOGGING_ON_SLACK', false),
];

