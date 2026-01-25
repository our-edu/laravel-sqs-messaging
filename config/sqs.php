<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SQS Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AWS SQS message queuing service.
    |
    */
    'region' => env('AWS_DEFAULT_REGION', 'us-east-2'),
    'access_key_id' => env('AWS_SQS_ACCESS_KEY_ID'),
    'secret_access_key' => env('AWS_SQS_SECRET_ACCESS_KEY'),
    /*
    |--------------------------------------------------------------------------
    | Queue Prefix
    |--------------------------------------------------------------------------
    |
    | Queue names will be prefixed with this value to ensure environment
    | isolation. Defaults to APP_ENV, but can be overridden with
    | SQS_QUEUE_PREFIX environment variable.
    |
    */

    'prefix' => env('SQS_QUEUE_PREFIX', env('APP_ENV', 'dev')),

    /*
    |--------------------------------------------------------------------------
    | Auto Ensure Queues
    |--------------------------------------------------------------------------
    |
    | Automatically ensure queues exist on application boot.
    | Set to false to disable (recommended for production).
    |
    */

    'auto_ensure' => env('SQS_AUTO_ENSURE', false),

    /*
    |--------------------------------------------------------------------------
    | Long Running Events
    |--------------------------------------------------------------------------
    |
    | Events that typically take longer to process. The visibility timeout
    | will be automatically extended for these events to prevent premature
    | reprocessing.
    |
    */

    'long_running_events' => [
        // Add event types here, e.g.:
        // 'PaymentProcessed',
        // 'GenerateReport',
    ],

    /*
    |--------------------------------------------------------------------------
    | CloudWatch Metrics
    |--------------------------------------------------------------------------
    |
    | Configuration for sending SQS metrics to AWS CloudWatch.
    |
    */

    'cloudwatch' => [
        'enabled' => env('SQS_CLOUDWATCH_ENABLED', true),
        'namespace' => env('SQS_CLOUDWATCH_NAMESPACE', 'SQS/PaymentService'),
    ],
];

