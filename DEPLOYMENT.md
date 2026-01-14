# Production Deployment Guide

Quick reference for production deployment. For installation and configuration, see `README.md`. For commands, see `COMMANDS.md`.

---

## 🔐 IAM Permissions

Your IAM user/role needs these permissions:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "QueueProvisioning",
            "Effect": "Allow",
            "Action": [
                "sqs:CreateQueue",
                "sqs:SetQueueAttributes",
                "sqs:GetQueueAttributes",
                "sqs:GetQueueUrl",
                "sqs:ListQueues"
            ],
            "Resource": "*"
        },
        {
            "Sid": "PublishMessages",
            "Effect": "Allow",
            "Action": [
                "sqs:SendMessage"
            ],
            "Resource": "arn:aws:sqs:us-east-2:*:*"
        },
        {
            "Sid": "ConsumeMessages",
            "Effect": "Allow",
            "Action": [
                "sqs:ReceiveMessage",
                "sqs:DeleteMessage",
                "sqs:ChangeMessageVisibility",
                "sqs:GetQueueAttributes"
            ],
            "Resource": "arn:aws:sqs:us-east-2:*:*"
        },
        {
            "Sid": "DlqOperations",
            "Effect": "Allow",
            "Action": [
                "sqs:ReceiveMessage",
                "sqs:DeleteMessage",
                "sqs:SendMessage"
            ],
            "Resource": "arn:aws:sqs:us-east-2:*:*-dlq"
        },
        {
            "Sid": "CloudWatchMetrics",
            "Effect": "Allow",
            "Action": [
                "cloudwatch:PutMetricData"
            ],
            "Resource": "*"
        }
    ]
}
```

---

## ⚙️ Production Environment Variables

### Production Recommendations

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

**Note:** Set `MESSAGING_FALLBACK_TO_RABBITMQ=true` if you have other projects still using RabbitMQ. This ensures messages go to RabbitMQ when SQS queue doesn't exist.

---

## 🚀 Quick Deployment Checklist

- [ ] IAM permissions configured
- [ ] Production environment variables set
- [ ] Queues ensured: `php artisan sqs:ensure`
- [ ] Workers started via Supervisor
- [ ] Connection tested: `php artisan sqs:test:connection`
- [ ] DLQ monitoring set up (cron job)

---

## 📝 Notes

- See `README.md` for installation and configuration
- See `COMMANDS.md` for all available commands
- See `ROLLBACK_GUIDE.md` for rollback procedures
