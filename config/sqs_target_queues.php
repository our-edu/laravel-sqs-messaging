<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SQS Target Queue Mapping
    |--------------------------------------------------------------------------
    |
    | Maps event types (from MessageEnvelope.event_type) to target SQS queue names.
    | This determines which consumer service queue receives each event.
    |
    | Queue names will be automatically prefixed with environment prefix:
    | {env}-{queue-name}
    |
    | Example: 'local-admission-service-queue', 'dev-admission-service-queue'
    |
    */

    // Add your event type to queue mappings here
    // 'payment:payment:student.subscribe.and.pay' => 'admission-service-queue',
    // 'payment:payment:bus.subscribe.and.unsubscribe' => 'transportation-service-queue',
    
    /*
    |--------------------------------------------------------------------------
    | Default Target Queue
    |--------------------------------------------------------------------------
    |
    | If an event type is not found in the mapping above, it will use this
    | default queue. Most events go to admission-service-queue.
    |
    */
    'default' => 'admission-service-queue',
    
    /*
    |--------------------------------------------------------------------------
    | Note on Queue Naming
    |--------------------------------------------------------------------------
    |
    | Queue names will be automatically prefixed with environment:
    | - local-{queue-name}  (local development)
    | - dev-{queue-name}    (development environment)
    | - staging-{queue-name} (staging environment)
    | - prod-{queue-name}   (production environment)
    |
    | Example: 'admission-service-queue' becomes 'local-admission-service-queue'
    |
    | The prefix comes from: config('sqs.prefix') or APP_ENV or 'dev' (default)
    |
    */
];

