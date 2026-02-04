# Upgrade to v2.0.0  Guide

## Overview

#### This guide explains :
        
the needed changes in each service to use the new version of this package 2.0.0

---

### Step 1: sqs.php config file

**Add to `sqs.php`:**

```env
    'region' => env('AWS_DEFAULT_REGION', 'us-east-2'),
    'access_key_id' => env('AWS_SQS_ACCESS_KEY_ID'),
    'secret_access_key' => env('AWS_SQS_SECRET_ACCESS_KEY'),
 ```

### Step 2: Update composer.json
   in composer.json update the following line 
```bash
        "our-edu/laravel-sqs-messaging": "1.*",
```
to
```bash
        "our-edu/laravel-sqs-messaging": "2.*",
```
