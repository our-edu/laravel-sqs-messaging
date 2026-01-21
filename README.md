# Laravel SQS Messaging Package

A comprehensive Laravel package for AWS SQS messaging with RabbitMQ rollback support, idempotency, error handling, and CloudWatch metrics integration.

## Features

- ✅ AWS SQS integration with automatic queue/DLQ creation
- ✅ Message envelope with idempotency keys
- ✅ Redis + Database idempotency checking
- ✅ Error classification (validation, transient, permanent)
- ✅ CloudWatch metrics integration
- ✅ Dead Letter Queue (DLQ) management
- ✅ Long polling support
- ✅ RabbitMQ adapter for easy migration
- ✅ Environment-aware queue naming

## Installation

### Via Composer

```bash
composer require our-edu/laravel-sqs-messaging:1.*
```

## Configuration

### 1. Publish Configuration

```bash
php artisan vendor:publish --provider="OurEdu\SqsMessaging\SqsMessagingServiceProvider"
```

### 2. Environment Variables

Add to your `.env`:

```env
# AWS Credentials (required)
AWS_SQS_ACCESS_KEY_ID=your-access-key
AWS_SQS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=me-central-1

# SQS Configuration
SQS_QUEUE_PREFIX=staging  # or production, dev, etc.

# Messaging Driver
MESSAGING_DRIVER=sqs        # Use SQS (default) or rabbitmq for rollback
MESSAGING_FALLBACK_TO_RABBITMQ=true  # Enable fallback to RabbitMQ when SQS queue doesn't exist

# CloudWatch Metrics (optional)
SQS_CLOUDWATCH_ENABLED=true
SQS_CLOUDWATCH_NAMESPACE=SQS/PaymentService
```

**Note:** Set `MESSAGING_FALLBACK_TO_RABBITMQ=true` if you have other projects still using RabbitMQ. This ensures messages go to RabbitMQ when SQS queue doesn't exist.

### 3. Configure Queues

Edit `config/sqs_queues.php`:

```php
return [
    'payment' => [
        'default' => 'payment-service-queue',
        'specific' => [
            'refunds' => 'payment-service-refunds-queue',
        ],
    ],
];
```

### 4. Configure Event Mappings

Edit `config/sqs_events.php`:

```php
return [
    'StudentEnrolled' => \App\Events\StudentEnrolled::class,
    'StudentWithdraw' => \App\Events\StudentWithdraw::class,
];
```

### 5. Run Migration

```bash
php artisan migrate
```

This creates the `processed_events` table for idempotency.

### 6. Ensure Queues Exist

```bash
php artisan sqs:ensure
```

**For Production:** Add this to your `entrypoint.sh` or container startup script to ensure queues exist before starting consumers:

```bash
php artisan sqs:ensure || echo "Warning: SQS queue ensure failed, but continuing startup..."
```

## Usage

### Publishing Messages

#### Option 1: Use Unified MessagingService (Recommended)

The package includes a `MessagingService` that can switch between available drivers (SQS and RabbitMQ).

**Step 1:** Update your `Support\RabbitMQ\Publishable` trait and add the following method:

```php
public static function publishFromInstance(object $event): void
{
    Container::getInstance()
        ->make(Publisher::class)
        ->publish($event);
}
```

**Complete updated trait:**

```php
<?php

declare(strict_types=1);

namespace Support\RabbitMQ;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

trait Publishable
{
    /**
     * @return void
     * @throws BindingResolutionException
     */
    public static function publish(): void
    {
        Container::getInstance()
            ->make(Publisher::class)
            ->publish(new static(...func_get_args()));
    }
    
    public static function publishFromInstance(object $event): void
    {
        Container::getInstance()
            ->make(Publisher::class)
            ->publish($event);
    }
}
```

**Step 2:** In your listeners where you publish a message, replace RabbitMQ publishing code:

**Old RabbitMQ Publisher:**
```php
Notification::publish($queueName, $payload)
```

**New MessagingService Publisher:**
```php
app(MessagingService::class)->publish(
    event: new Notification($queueName, $payload),
    eventClassReference: Notification::class
);
```

**Example Migration:**

**Old RabbitMQ Listener:**
```php
namespace Domain\Payments\Listeners\ReversePaymentRequest;

use App\BaseApp\Enums\RoleEnum;
use Domain\Models\User;
use Domain\Payments\Events\ReversePaymentRequest\ReversePaymentRequestCreatedEvent;
use Domain\Payments\Notifications\ReversePaymentRequestNotification;
use Illuminate\Contracts\Container\BindingResolutionException;

class ReversePaymentRequestCreatedListener
{
    /**
     * @throws BindingResolutionException
     */
    public function handle(ReversePaymentRequestCreatedEvent $event): void
    {
        $users = User::query()->whereHas('roles', function ($query) {
            $query->whereIn('name', [RoleEnum::ACCOUNTANT_MANAGER, RoleEnum::ACCOUNTANT_MANAGER]);
        })->get();
        $usersUuids = $users->pluck('uuid')->toArray();
        ReversePaymentRequestNotification::publish($event->reversePaymentRequest, $usersUuids);
    }
}
```

**New Listener using MessagingService:**
```php
namespace Domain\Payments\Listeners\ReversePaymentRequest;

use App\BaseApp\Enums\RoleEnum;
use Domain\Models\User;
use Domain\Payments\Events\ReversePaymentRequest\ReversePaymentRequestCreatedEvent;
use Domain\Payments\Notifications\ReversePaymentRequestNotification;
use Illuminate\Contracts\Container\BindingResolutionException;
use OurEdu\SqsMessaging\MessagingService;

class ReversePaymentRequestCreatedListener
{
    /**
     * @throws BindingResolutionException
     */
    public function handle(ReversePaymentRequestCreatedEvent $event): void
    {
        $users = User::query()->whereHas('roles', function ($query) {
            $query->whereIn('name', [RoleEnum::ACCOUNTANT_MANAGER, RoleEnum::ACCOUNTANT_MANAGER]);
        })->get();
        $usersUuids = $users->pluck('uuid')->toArray();
        
        // New messaging service publish
        app(MessagingService::class)->publish(
            event: new ReversePaymentRequestNotification(queueName: $this->queueName, payload: $event->students),
            eventClassReference: ReversePaymentRequestNotification::class
        );
    }
}
```

**Switch drivers via environment variable:**
```env
MESSAGING_DRIVER=sqs        # Use SQS (default)
MESSAGING_DRIVER=rabbitmq   # Rollback to RabbitMQ
```

#### Option 2: Direct SQS Adapter

**HINT:** This approach will publish directly to SQS (bypasses driver switching):

```php
use App\Events\StudentEnrolled;
use OurEdu\SqsMessaging\Drivers\Sqs\SQSPublisherAdapter;

$adapter = app(SQSPublisherAdapter::class);
$adapter->publish(new StudentEnrolled($student), 'payment-service-queue');
```

### Consuming Messages

Add to Supervisor configuration:

```ini
[program:sqs-payment-consumer]
command=php /var/www/artisan sqs:consume payment-service-queue
autostart=true
autorestart=true
user=www-data
numprocs=2
stdout_logfile=/var/www/storage/logs/payment-consumer.log
```

## Available Commands

```bash
# Ensure all queues exist
php artisan sqs:ensure

# Consume messages from a queue
php artisan sqs:consume {queue}

# Test AWS connection
php artisan sqs:test:connection

# Inspect DLQ messages
php artisan sqs:inspect-dlq {queue}

# Monitor DLQ depth
php artisan sqs:monitor-dlq

# Replay DLQ messages
php artisan sqs:replay-dlq {queue} --limit=10

# Cleanup old processed events
php artisan sqs:cleanup-processed-events --days=7
```

### Rollback & Cleanup

See `ROLLBACK_GUIDE.md` for detailed instructions on:
- Rolling back from SQS to RabbitMQ
- Cleaning up RabbitMQ code once SQS is stable
- Dual write mode for gradual migration

## Production Deployment

### Ensure Queues Before Startup

Add to your production `entrypoint.sh` or container startup script:

```bash
php artisan sqs:ensure || echo "Warning: SQS queue ensure failed, but continuing startup..."
```

This ensures all required SQS queues and DLQs exist before starting consumers or the application.

### Production Environment Variables

```env
# AWS Authentication (REQUIRED)
AWS_SQS_ACCESS_KEY_ID=your-production-access-key
AWS_SQS_SECRET_ACCESS_KEY=your-production-secret-key
AWS_DEFAULT_REGION=us-east-2

# Production Settings
SQS_QUEUE_PREFIX=production
SQS_AUTO_ENSURE=false              # Disable auto-creation in production
SQS_CLOUDWATCH_ENABLED=true        # Enable monitoring
MESSAGING_DRIVER=sqs               # Use SQS
MESSAGING_DUAL_WRITE=false         # Disable dual write
MESSAGING_FALLBACK_TO_RABBITMQ=true  # Enable fallback to resolve other projects that work with rabbitmq
```

For detailed production deployment guide, see `DEPLOYMENT.md`.

## Requirements

- PHP ^8.1
- Laravel ^9.0|^10.0
- AWS SDK PHP ^3.0
- Redis (for idempotency)
- AWS SQS access

## License

MIT

## Support

For issues and questions, please contact the OurEdu development team.
