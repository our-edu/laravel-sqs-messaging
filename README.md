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

### Option 1: Via Composer (Recommended for Production)

```bash
composer require ouredu/laravel-sqs-messaging
```

### Option 2: Via Path Repository (For Development)

Add to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-sqs-messaging"
        }
    ],
    "require": {
        "ouredu/laravel-sqs-messaging": "*"
    }
}
```

Then run:
```bash
composer require ouredu/laravel-sqs-messaging
```

## Configuration

### 1. Publish Configuration

```bash
php artisan vendor:publish --provider="OurEdu\SqsMessaging\SqsMessagingServiceProvider" --tag="config"
```

### 2. Environment Variables

Add to your `.env`:

```env
# AWS Credentials (required)
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=me-central-1

# SQS Configuration
SQS_QUEUE_PREFIX=staging  # or production, dev, etc.

# CloudWatch Metrics (optional)
SQS_CLOUDWATCH_ENABLED=true
SQS_CLOUDWATCH_NAMESPACE=SQS/PaymentService
```

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

## Usage

### Publishing Messages

```php
use OurEdu\SqsMessaging\Sqs\SQSPublisher;

$publisher = app(SQSPublisher::class);

$publisher->publish(
    'payment-service-queue',
    'StudentEnrolled',
    ['student_uuid' => $student->uuid, 'amount' => 1000]
);
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

### Available Commands

```bash
# Ensure all queues exist
php artisan sqs:ensure

# Consume messages from a queue
php artisan sqs:consume {queue}

# Inspect DLQ messages
php artisan sqs:inspect-dlq {queue}

# Monitor DLQ depth
php artisan sqs:monitor-dlq

# Replay DLQ messages
php artisan sqs:replay-dlq {queue} --limit=10

# Cleanup old processed events
php artisan sqs:cleanup-processed-events --days=7
```

## Migration from RabbitMQ

### Option 1: Use Unified MessagingService (Recommended)

The package includes a `MessagingService` that can switch between SQS and RabbitMQ:

```php
use OurEdu\SqsMessaging\MessagingService;
use OurEdu\SqsMessaging\Sqs\SQSTargetQueueResolver;

$messaging = app(MessagingService::class);
$notification = new Notification($queueName, $payload);

// Resolve target queue (for SQS) - ignored for RabbitMQ
$targetQueue = SQSTargetQueueResolver::resolve($queueName);

// Automatically uses SQS or RabbitMQ based on MESSAGING_DRIVER
$messaging->publish($notification, $targetQueue);
```

**Switch drivers via environment variable:**
```env
MESSAGING_DRIVER=sqs        # Use SQS (default)
MESSAGING_DRIVER=rabbitmq   # Rollback to RabbitMQ
```

### Option 2: Direct SQS Adapter

```php
use OurEdu\SqsMessaging\Sqs\SQSPublisherAdapter;
use App\Events\StudentEnrolled;

$adapter = app(SQSPublisherAdapter::class);
$adapter->publish(new StudentEnrolled($student), 'payment-service-queue');
```

### Rollback & Cleanup

See `ROLLBACK_GUIDE.md` for detailed instructions on:
- Rolling back from SQS to RabbitMQ
- Cleaning up RabbitMQ code once SQS is stable
- Dual write mode for gradual migration

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

