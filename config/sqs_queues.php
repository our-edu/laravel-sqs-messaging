<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SQS Queue Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines all queues owned by the payment service. Each queue
    | will be automatically created with its corresponding DLQ when the
    | sqs:ensure command is run.
    |
    | Queue names will be automatically prefixed with the environment
    | (dev, staging, production) to ensure isolation.
    |
    */

    'payment' => [
        'default' => 'payment-service-queue',
        'specific' => [
            // Add specific queues here if needed
            'payment-service-refunds-queue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Naming Convention
    |--------------------------------------------------------------------------
    |
    | Queues are named as: {env}-{queue-name}
    | DLQs are named as: {env}-{queue-name}-dlq
    |
    | Example:
    | - dev-payment-service-queue
    | - dev-payment-service-queue-dlq
    | - staging-payment-service-queue
    | - production-payment-service-queue
    |
    */
];

