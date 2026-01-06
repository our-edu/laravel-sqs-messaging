# Commands Reference

Complete reference for all Laravel SQS Messaging package commands.

---

## 📋 Table of Contents

- [Consumer Commands](#consumer-commands)
- [Queue Management](#queue-management)
- [DLQ Commands](#dlq-commands)
- [Testing Commands](#testing-commands)
- [Maintenance Commands](#maintenance-commands)

---

## 🔄 Consumer Commands

### `sqs:consume`

Consume messages from an SQS queue. This is the main worker command that should be run via Supervisor.

**Usage:**
```bash
php artisan sqs:consume {queue}
```

**Arguments:**
- `queue` - The SQS queue name to consume (without prefix)

**Examples:**
```bash
# Consume from payment service queue
php artisan sqs:consume payment-service-queue

# Consume from admission service queue
php artisan sqs:consume admission-service-queue
```

**Features:**
- Long polling (20 seconds)
- Message validation
- Idempotency checking
- Error classification (transient/permanent)
- Visibility timeout extension for long-running events
- CloudWatch metrics integration
- Automatic DLQ handling

**Note:** This command is designed to be managed by Supervisor. It exits after each polling cycle, and Supervisor restarts it.

---

## 🗄️ Queue Management

### `sqs:ensure`

Ensure all SQS queues and DLQs are created. Recommended to run before starting workers in production.

**Usage:**
```bash
php artisan sqs:ensure
```

**Description:**
- Creates all queues defined in `config/sqs_queues.php`
- Creates corresponding DLQs automatically
- Useful for CI/CD pipelines
- Safe to run multiple times (idempotent)

**Example:**
```bash
php artisan sqs:ensure
```

**Output:**
```
🔍 Ensuring SQS queues exist...

📋 Service: payment
  ✅ payment-service-queue
  ✅ payment-service-refunds-queue

📋 Service: admission
  ✅ admission-service-queue

✅ Completed: 3 queue(s) ensured
```

---

### `sqs:status`

Check SQS system status: queue depth, Redis jobs, and workers.

**Usage:**
```bash
php artisan sqs:status {--queue=payment-service-queue}
```

**Options:**
- `--queue` - Queue name to check (default: `payment-service-queue`)

**Examples:**
```bash
# Check default queue
php artisan sqs:status

# Check specific queue
php artisan sqs:status --queue=admission-service-queue
```

**Output:**
```
📊 SQS Status: payment-service-queue

Queue Depth: 15 messages
DLQ Depth: 0 messages
Redis Jobs: 0
Workers: 3 running
```

---

### `sqs:check`

Check messages in SQS queue and queue depth.

**Usage:**
```bash
php artisan sqs:check {--queue=payment-service-queue}
```

**Options:**
- `--queue` - Queue name to check (default: `payment-service-queue`)

**Examples:**
```bash
# Check default queue
php artisan sqs:check

# Check specific queue
php artisan sqs:check --queue=admission-service-queue
```

**Output:**
```
📋 Queue: payment-service-queue
📊 Depth: 15 messages
🔗 URL: https://sqs.us-east-2.amazonaws.com/123456789/prod-payment-service-queue
```

---

## 💀 DLQ Commands

### `sqs:inspect-dlq`

Inspect messages in Dead Letter Queue for investigation. Useful for debugging failed messages.

**Usage:**
```bash
php artisan sqs:inspect-dlq {queue} {--limit=10}
```

**Arguments:**
- `queue` - Queue name (DLQ will be `{queue}-dlq`)

**Options:**
- `--limit` - Number of messages to inspect (default: `10`)

**Examples:**
```bash
# Inspect 10 messages from DLQ
php artisan sqs:inspect-dlq payment-service-queue

# Inspect 50 messages
php artisan sqs:inspect-dlq payment-service-queue --limit=50
```

**Output:**
```
🔍 Inspecting DLQ: payment-service-queue-dlq
Found 5 message(s) in DLQ

Message 1:
  ID: abc-123-def
  Event Type: payment:payment:student.subscribe.and.pay
  Receive Count: 5
  Timestamp: 2024-01-15 10:30:00
  Payload: {"student_id": 123, "amount": 500}
```

---

### `sqs:monitor-dlq`

Monitor Dead Letter Queue depth and alert if high. Recommended to run daily via cron.

**Usage:**
```bash
php artisan sqs:monitor-dlq {queue?}
```

**Arguments:**
- `queue` - Specific queue to check (optional, checks all if omitted)

**Examples:**
```bash
# Monitor all queues
php artisan sqs:monitor-dlq

# Monitor specific queue
php artisan sqs:monitor-dlq payment-service-queue
```

**Output:**
```
📊 Monitoring DLQ depth...

DLQ: payment-service-queue-dlq
  Depth: 0 messages ✅

DLQ: admission-service-queue-dlq
  Depth: 5 messages ⚠️

⚠️  ALERT: High DLQ depth (5) for admission-service-queue
```

**Cron Setup:**
```bash
# Add to crontab (runs daily at 9 AM)
0 9 * * * cd /path/to/app && php artisan sqs:monitor-dlq >> /dev/null 2>&1
```

---

### `sqs:replay-dlq`

Replay messages from Dead Letter Queue back to main queue. Use after fixing the issue that caused messages to fail.

**Usage:**
```bash
php artisan sqs:replay-dlq {queue} {--limit=10}
```

**Arguments:**
- `queue` - Queue name (DLQ will be `{queue}-dlq`)

**Options:**
- `--limit` - Number of messages to replay (default: `10`)

**Examples:**
```bash
# Replay 10 messages
php artisan sqs:replay-dlq payment-service-queue

# Replay 50 messages
php artisan sqs:replay-dlq payment-service-queue --limit=50
```

**Output:**
```
🔄 Replaying messages from DLQ: payment-service-queue-dlq
Found 5 message(s) in DLQ

✅ Replayed: payment:payment:student.subscribe.and.pay (Message ID: abc-123)
✅ Replayed: payment:payment:student.subscribe.and.pay (Message ID: def-456)
...

✅ Replayed: 5 message(s)
```

**⚠️ Warning:** Only replay messages after fixing the root cause. Replaying without fixing will cause messages to fail again.

---

## 🧪 Testing Commands

### `sqs:test:connection`

Test AWS SQS connection and credentials. Useful for troubleshooting connection issues.

**Usage:**
```bash
php artisan sqs:test:connection
```

**Examples:**
```bash
php artisan sqs:test:connection
```

**Output:**
```
🔍 Testing AWS Connection...

✅ AWS Connection: OK
✅ Region: us-east-2
✅ Credentials: Valid
✅ SQS Service: Accessible
```

**Use Cases:**
- Verify AWS credentials
- Check IAM permissions
- Troubleshoot connection issues
- Pre-deployment verification

---

### `sqs:test:receive`

Test receiving messages from SQS queue without processing them.

**Usage:**
```bash
php artisan sqs:test:receive {queue}
```

**Arguments:**
- `queue` - Queue name to test

**Examples:**
```bash
php artisan sqs:test:receive payment-service-queue
```

**Output:**
```
🔍 Testing SQS Receive...

Queue: payment-service-queue
Messages Found: 3

Message 1:
  ID: msg-123
  Body: {"event_type":"payment:payment:student.subscribe.and.pay",...}
```

**Use Cases:**
- Verify queue is accessible
- Check message format
- Debug message structure
- Test before deploying workers

---

## 🧹 Maintenance Commands

### `sqs:cleanup-processed-events`

Clean up old processed_events records from database. Recommended to run weekly.

**Usage:**
```bash
php artisan sqs:cleanup-processed-events {--days=7}
```

**Options:**
- `--days` - Number of days to keep records (default: `7`)

**Examples:**
```bash
# Keep last 7 days (default)
php artisan sqs:cleanup-processed-events

# Keep last 30 days
php artisan sqs:cleanup-processed-events --days=30

# Keep last 90 days
php artisan sqs:cleanup-processed-events --days=90
```

**Output:**
```
🧹 Cleaning up processed events...

Deleted records older than 7 days: 1,234 records
✅ Cleanup completed
```

**Cron Setup:**
```bash
# Add to crontab (runs weekly on Sunday at 2 AM)
0 2 * * 0 cd /path/to/app && php artisan sqs:cleanup-processed-events --days=30 >> /dev/null 2>&1
```

**Note:** This only cleans up database records. Redis keys expire automatically based on TTL.

---

## 📊 Command Summary

| Command | Purpose | Frequency |
|---------|---------|-----------|
| `sqs:consume` | Main worker (via Supervisor) | Continuous |
| `sqs:ensure` | Create queues | Once per deployment |
| `sqs:status` | Check system status | As needed |
| `sqs:check` | Check queue depth | As needed |
| `sqs:inspect-dlq` | Debug failed messages | When issues occur |
| `sqs:monitor-dlq` | Monitor DLQ depth | Daily (cron) |
| `sqs:replay-dlq` | Replay failed messages | After fixing issues |
| `sqs:test:connection` | Test AWS connection | Pre-deployment |
| `sqs:test:receive` | Test message receive | Debugging |
| `sqs:cleanup-processed-events` | Cleanup database | Weekly (cron) |

---

For general help:
```bash
php artisan list sqs
```

