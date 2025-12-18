# Rollback & Cleanup Guide

## Overview

This guide explains how to:
1. **Rollback from SQS to RabbitMQ** if issues occur
2. **Clean up RabbitMQ code** once SQS is stable

## Strategy: Feature Flag Approach

We use a **unified MessagingService** that can switch between SQS and RabbitMQ via configuration.

---

## Part 1: Rollback Plan (SQS → RabbitMQ)

### Step 1: Update Environment Variable

**Change in `.env`:**

```env
# Switch from SQS to RabbitMQ
MESSAGING_DRIVER=rabbitmq
```

### Step 2: Restart Services

```bash
# Restart Supervisor to reload config
supervisorctl reread
supervisorctl update
supervisorctl restart all

# Or restart Docker container
docker-compose restart
```

### Step 3: Verify Rollback

1. **Check Supervisor processes:**
   ```bash
   supervisorctl status
   ```
   - RabbitMQ consumers should be running
   - SQS consumers should be stopped (or comment them out)

2. **Test message publishing:**
   ```bash
   # Publish a test message
   php artisan tinker
   >>> $service = app(\OurEdu\SqsMessaging\MessagingService::class);
   >>> $service->getDriver(); // Should return 'rabbitmq'
   ```

3. **Monitor logs:**
   ```bash
   tail -f storage/logs/worker.log
   ```

### Step 4: Disable SQS Consumers (Optional)

Comment out SQS consumers in Supervisor config:

```ini
# [program:sqs_payment_service_consumer]
# process_name=%(program_name)s_%(process_num)02d
# command=php /var/www/artisan sqs:consume payment-service-queue
# autostart=false
# autorestart=false
```

---

## Part 2: Update Code to Use Unified Service

### Current Pattern (Mixed)

```php
// ❌ BAD: Hard-coded SQS
$adapter = app(SQSPublisherAdapter::class);
$adapter->publish($notification, $targetQueue);

// ❌ BAD: Hard-coded RabbitMQ
Notification::publish($this->queueName, $event->students);
```

### New Pattern (Unified)

```php
// ✅ GOOD: Uses config to switch automatically
use OurEdu\SqsMessaging\Drivers\Sqs\SQSTargetQueueResolver;
use OurEdu\SqsMessaging\MessagingService;

$messaging = app(MessagingService::class);
$notification = new Notification($this->queueName, $event->students);

// For SQS: queue name is required
// For RabbitMQ: queue name is optional (uses event key)
$targetQueue = SQSTargetQueueResolver::resolve($this->queueName);
$messaging->publish($notification, $targetQueue);
```

### Migration Example

**Before:**
```php
class NotifyAdmissionThatStudentSubscribeAndPayListener
{
    public function handle($event): void
    {
        // SQS (New way)
        $adapter = app(SQSPublisherAdapter::class);
        $notification = new Notification($this->queueName, $event->students);
        $targetQueue = SQSTargetQueueResolver::resolve($this->queueName);
        $adapter->publish($notification, $targetQueue);
        
        // RabbitMQ (Old way - comment out when fully migrated)
        // Notification::publish($this->queueName, $event->students);
    }
}
```

**After:**

```php
use OurEdu\SqsMessaging\Drivers\Sqs\SQSTargetQueueResolver;
use OurEdu\SqsMessaging\MessagingService;

class NotifyAdmissionThatStudentSubscribeAndPayListener
{
    public function handle($event): void
    {
        $messaging = app(MessagingService::class);
        $notification = new Notification($this->queueName, $event->students);
        
        // Resolve target queue (for SQS) - ignored for RabbitMQ
        $targetQueue = SQSTargetQueueResolver::resolve($this->queueName);
        
        // Automatically uses SQS or RabbitMQ based on MESSAGING_DRIVER
        $messaging->publish($notification, $targetQueue);
    }
}
```

---

## Part 3: Cleanup Plan (Remove RabbitMQ)

### Prerequisites

✅ SQS is stable in all microservices (2-4 weeks)  
✅ All services migrated to SQS  
✅ No rollback needed  
✅ RabbitMQ consumers stopped for 1+ week  

### Step 1: Verify SQS Stability

```bash
# Check SQS metrics in CloudWatch
# - Error rate < 1%
# - DLQ depth = 0
# - Processing latency acceptable
# - No critical issues for 2+ weeks
```

### Step 2: Remove RabbitMQ from Supervisor

**Edit `.docker/supervisor/prod/app.conf`:**

Remove or comment out all RabbitMQ consumers:

```ini
# ============================================================================
# RabbitMQ Consumers (DEPRECATED - Use SQS instead)
# ============================================================================

# [program:admission_finish_move_student]
# process_name=%(program_name)s_%(process_num)02d
# command=php /var/www/artisan rabbitevents:listen admission:finish.move.students
# autostart=false
# autorestart=false
```

### Step 3: Remove RabbitMQ Dependencies

**Edit `composer.json`:**

```json
{
    "require": {
        // Remove these:
        // "nuwber/rabbitevents": "^8.1",
        // "php-amqplib/php-amqplib": "^3.5",
    }
}
```

Then:
```bash
composer update
```

### Step 4: Remove RabbitMQ Code

**Files to delete:**

```
core/src/Support/RabbitMQ/
├── Publisher.php
├── ShouldPublish.php
├── Publishable.php
├── AmqpConnectionFactory.php
└── (any other RabbitMQ files)
```

**Config files:**
```
core/config/rabbitevents.php  # Delete or keep for reference
```

### Step 5: Update MessagingService

**Edit `packages/oureedu/laravel-sqs-messaging/src/MessagingService.php`:**

Remove RabbitMQ support:

```php
// Remove RabbitMQ code, keep only SQS
public function publish($event, ?string $queueName = null): string
{
    if (!$queueName) {
        $eventType = method_exists($event, 'publishEventKey') 
            ? $event->publishEventKey() 
            : get_class($event);
        $queueName = SQSTargetQueueResolver::resolve($eventType);
    }
    
    return $this->sqsAdapter->publish($event, $queueName);
}
```

### Step 6: Remove Environment Variables

**Remove from `.env`:**

```env
# Remove these:
# RABBITEVENTS_CONNECTION=rabbitmq
# RABBITEVENTS_EXCHANGE=events
# RABBITEVENTS_HOST=localhost
# RABBITEVENTS_PORT=5672
# RABBITEVENTS_USER=guest
# RABBITEVENTS_PASSWORD=guest
# RABBITEVENTS_VHOST=/
# RABBITMQ_SERVICE_NAME=communication:communication
# RABBITEVENTS_SSL_ENABLED=true
# RABBITEVENTS_SSL_VERIFY_PEER=false
```

### Step 7: Clean Up Code References

**Search and remove:**

```bash
# Find all RabbitMQ references
grep -r "rabbitevents" core/src
grep -r "RabbitMQ" core/src
grep -r "ShouldPublish" core/src
```

**Update listeners** to remove commented RabbitMQ code:

```php
// Remove these comments:
// RabbitMQ (Old way - comment out when fully migrated)
// Notification::publish($this->queueName, $event->students);
```

### Step 8: Update Package

**Remove RabbitMQ adapter from package** (if not needed):

The `SQSPublisherAdapter` can stay (it's SQS-specific), but remove any RabbitMQ-specific code.

### Step 9: Archive RabbitMQ Code (Optional)

Before deleting, create a backup branch:

```bash
git checkout -b archive/rabbitmq-code
git add .
git commit -m "Archive RabbitMQ code before removal"
git push origin archive/rabbitmq-code
```

Then delete in main branch.

---

## Part 4: Dual Write Mode (Gradual Migration)

### Enable Dual Write

**In `.env`:**

```env
MESSAGING_DRIVER=sqs
MESSAGING_DUAL_WRITE=true
```

**Update `MessagingService`:**

```php
public function publish($event, ?string $queueName = null)
{
    $sqsResult = null;
    $rabbitmqResult = null;
    
    // Always publish to SQS
    $sqsResult = $this->publishToSqs($event, $queueName);
    
    // Also publish to RabbitMQ if dual write enabled
    if (config('messaging.dual_write', false) && $this->rabbitmqPublisher) {
        try {
            $rabbitmqResult = $this->publishToRabbitMQ($event);
        } catch (\Throwable $e) {
            Log::warning('Dual write: RabbitMQ publish failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    return $sqsResult;
}
```

**⚠️ WARNING:** Dual write sends duplicate messages! Only use during migration testing.

---

## Part 5: Fallback Mode

### Enable Fallback

**In `.env`:**

```env
MESSAGING_DRIVER=sqs
MESSAGING_FALLBACK_TO_RABBITMQ=true
```

**Update `MessagingService`:**

```php
public function publish($event, ?string $queueName = null)
{
    if ($this->driver === 'sqs') {
        try {
            return $this->publishToSqs($event, $queueName);
        } catch (\Throwable $e) {
            if (config('messaging.fallback_to_rabbitmq', false)) {
                Log::warning('SQS publish failed, falling back to RabbitMQ', [
                    'error' => $e->getMessage()
                ]);
                return $this->publishToRabbitMQ($event);
            }
            throw $e;
        }
    }
    
    return $this->publishToRabbitMQ($event);
}
```

---

## Quick Reference

### Switch to RabbitMQ (Rollback)

```env
MESSAGING_DRIVER=rabbitmq
```

```bash
supervisorctl restart all
```

### Switch to SQS (Normal)

```env
MESSAGING_DRIVER=sqs
```

```bash
supervisorctl restart all
```

### Check Current Driver

```php
$messaging = app(\OurEdu\SqsMessaging\MessagingService::class);
echo $messaging->getDriver(); // 'sqs' or 'rabbitmq'
```

---

## Checklist

### Rollback Checklist
- [ ] Set `MESSAGING_DRIVER=rabbitmq` in `.env`
- [ ] Restart Supervisor/Docker
- [ ] Verify RabbitMQ consumers running
- [ ] Test message publishing
- [ ] Monitor logs for errors
- [ ] Comment out SQS consumers in Supervisor

### Cleanup Checklist
- [ ] SQS stable for 2+ weeks
- [ ] All services using SQS
- [ ] Remove RabbitMQ from Supervisor config
- [ ] Remove RabbitMQ from `composer.json`
- [ ] Delete RabbitMQ code files
- [ ] Remove RabbitMQ config files
- [ ] Remove RabbitMQ env variables
- [ ] Update all listeners to use `MessagingService`
- [ ] Remove commented RabbitMQ code
- [ ] Archive RabbitMQ code in Git branch
- [ ] Test application thoroughly
- [ ] Deploy to production

---

## Summary

**Best Practice:**
1. Use `MessagingService` everywhere (not direct SQS/RabbitMQ calls)
2. Control via `MESSAGING_DRIVER` env variable
3. Keep RabbitMQ code until SQS is proven stable
4. Clean up gradually after 2-4 weeks of stability

**Rollback:** Change one env variable  
**Cleanup:** Follow checklist after stability period

