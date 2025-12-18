# Rollback Quick Reference

## 🚨 Emergency Rollback (SQS → RabbitMQ)

### Step 1: Change Environment Variable

```env
MESSAGING_DRIVER=rabbitmq
```

### Step 2: Restart Services

```bash
# Docker
docker-compose restart

# Or Supervisor
supervisorctl restart all
```

### Step 3: Verify

```bash
# Check driver
php artisan tinker
>>> app(\OurEdu\SqsMessaging\MessagingService::class)->getDriver()
# Should return: "rabbitmq"
```

**That's it!** All messages now go through RabbitMQ.

---

## ✅ Switch Back to SQS

```env
MESSAGING_DRIVER=sqs
```

```bash
supervisorctl restart all
```

---

## 📋 Migration Checklist

### Phase 1: Update Code (Use MessagingService)

**Before:**
```php
// ❌ Hard-coded SQS
$adapter = app(SQSPublisherAdapter::class);
$adapter->publish($notification, $targetQueue);
```

**After:**

```php
// ✅ Unified service
use OurEdu\SqsMessaging\Drivers\Sqs\SQSTargetQueueResolver;
use OurEdu\SqsMessaging\MessagingService;

$messaging = app(MessagingService::class);
$targetQueue = SQSTargetQueueResolver::resolve($queueName);
$messaging->publish($notification, $targetQueue);
```

### Phase 2: Test with Dual Write (Optional)

```env
MESSAGING_DRIVER=sqs
MESSAGING_DUAL_WRITE=true
```

⚠️ **Warning:** This sends duplicate messages! Only use for testing.

### Phase 3: Production (SQS Only)

```env
MESSAGING_DRIVER=sqs
MESSAGING_DUAL_WRITE=false
MESSAGING_FALLBACK_TO_RABBITMQ=false
```

### Phase 4: Cleanup (After 2-4 Weeks)

See `ROLLBACK_GUIDE.md` Part 3 for detailed cleanup steps.

---

## 🔧 Configuration Options

| Environment Variable | Values | Description |
|---------------------|--------|------------|
| `MESSAGING_DRIVER` | `sqs`, `rabbitmq` | Primary messaging driver |
| `MESSAGING_DUAL_WRITE` | `true`, `false` | Send to both SQS and RabbitMQ |
| `MESSAGING_FALLBACK_TO_RABBITMQ` | `true`, `false` | Fallback if SQS fails |

---

## 📁 Files Created

- `src/MessagingService.php` - Unified messaging service
- `config/messaging.php` - Configuration file
- `ROLLBACK_GUIDE.md` - Detailed guide
- `ROLLBACK_QUICK_REFERENCE.md` - This file

---

## 🎯 Best Practice

1. **Always use `MessagingService`** (not direct SQS/RabbitMQ calls)
2. **Control via environment variables** (easy rollback)
3. **Keep RabbitMQ code** until SQS is proven stable (2-4 weeks)
4. **Clean up gradually** after stability period

---

## 📞 Need Help?

See `ROLLBACK_GUIDE.md` for:
- Detailed rollback steps
- Code migration examples
- Cleanup checklist
- Dual write mode
- Fallback mode

