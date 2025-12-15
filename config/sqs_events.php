<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SQS Event Type Mappings
    |--------------------------------------------------------------------------
    |
    | Maps event types (from MessageEnvelope.event_type) to Laravel event
    | listener classes. This is how the SqsConsumeCommand knows which
    | listener to invoke when processing a message.
    |
    | Event types should match those used by producers when publishing
    | messages via SQSPublisher.
    |
    */

    // Add your event mappings here
    // 'StudentEnrolled' => \App\Events\StudentEnrolled::class,
    // 'StudentWithdraw' => \App\Events\StudentWithdraw::class,
];

