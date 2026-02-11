# Upgrade to v2.0.0  Guide

## Overview

#### This guide explains :
        
the needed changes in each service to use the new version of this package 2.0.0

---

### Step 1: sqs.php config file

**Add to `sqs.php`:**

```env
'allow_timestamp_attribute' => env('SQS_ALLOW_TIMESTAMP_ATTRIBUTE', false),
 ```

**Important  in `sqs.php`: CHANGE**

in sqs.php update the following line
```bash
    'region' => env('AWS_DEFAULT_REGION', 'us-east-2'),
```
to
```bash
    'region' => env('AWS_SQS_DEFAULT_REGION', 'us-east-2'),
```
### Step 2: Update composer.json
   in composer.json update the following line 
```bash
        "our-edu/laravel-sqs-messaging": "2.*",
```
to
```bash
        "our-edu/laravel-sqs-messaging": "3.*",
```
