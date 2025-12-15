# Quick Start Guide - Laravel SQS Package

## ✅ What's Already Done

1. ✅ Package structure created
2. ✅ `composer.json` configured
3. ✅ Service Provider created
4. ✅ Example files with correct namespaces
5. ✅ README and documentation

## 📋 What You Need to Do

### Step 1: Copy Remaining Files

You need to copy ~20 more files and update their namespaces. See `MIGRATION_GUIDE.md` for details.

**Quick PowerShell Script:**

```powershell
$source = "c:\xampp\htdocs\OurEdu\new-payment-be\core"
$dest = "c:\xampp\htdocs\OurEdu\new-payment-be\packages\oureedu\laravel-sqs-messaging"

# Copy core classes
Copy-Item "$source\src\Support\Messaging\Sqs\*.php" -Destination "$dest\src\Sqs\" -Force

# Copy commands  
Copy-Item "$source\src\App\BaseApp\Commands\Sqs*.php" -Destination "$dest\src\Commands\" -Force
Copy-Item "$source\src\App\BaseApp\Commands\*Dlq*.php" -Destination "$dest\src\Commands\" -Force
Copy-Item "$source\src\App\BaseApp\Commands\CleanupProcessedEventsCommand.php" -Destination "$dest\src\Commands\" -Force
Copy-Item "$source\src\App\BaseApp\Commands\EnsureSqsQueuesCommand.php" -Destination "$dest\src\Commands\" -Force

# Copy configs
Copy-Item "$source\config\sqs*.php" -Destination "$dest\config\" -Force
```

### Step 2: Update Namespaces

In all copied PHP files, replace:

- `namespace Support\Messaging\Sqs;` → `namespace OurEdu\SqsMessaging\Sqs;`
- `namespace App\BaseApp\Commands;` → `namespace OurEdu\SqsMessaging\Commands;`
- `use Support\Messaging\Sqs\` → `use OurEdu\SqsMessaging\Sqs\`

**PowerShell Find & Replace:**

```powershell
Get-ChildItem -Path "$dest\src" -Recurse -Filter "*.php" | ForEach-Object {
    (Get-Content $_.FullName) -replace 'namespace Support\\Messaging\\Sqs;', 'namespace OurEdu\SqsMessaging\Sqs;' | Set-Content $_.FullName
    (Get-Content $_.FullName) -replace 'namespace App\\BaseApp\\Commands;', 'namespace OurEdu\SqsMessaging\Commands;' | Set-Content $_.FullName
    (Get-Content $_.FullName) -replace 'use Support\\Messaging\\Sqs\\', 'use OurEdu\SqsMessaging\Sqs\' | Set-Content $_.FullName
}
```

### Step 3: Test Locally

1. Add to `core/composer.json`:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/oureedu/laravel-sqs-messaging"
        }
    ]
}
```

2. Install:
```bash
cd core
composer require oureedu/laravel-sqs-messaging
```

3. Publish:
```bash
php artisan vendor:publish --provider="OurEdu\SqsMessaging\SqsMessagingServiceProvider"
```

4. Test:
```bash
php artisan sqs:ensure
```

### Step 4: Push to Git

**Option A: New Repository (Recommended)**

```bash
cd packages/oureedu/laravel-sqs-messaging
git init
git add .
git commit -m "Initial package release v1.0.0"
git branch -M main
git remote add origin https://github.com/oureedu/laravel-sqs-messaging.git
git push -u origin main
git tag -a v1.0.0 -m "Initial release"
git push origin v1.0.0
```

**Option B: New Branch in Current Repo**

```bash
git checkout -b feature/sqs-package
git add packages/oureedu/laravel-sqs-messaging
git commit -m "Add SQS messaging package"
git push origin feature/sqs-package
```

### Step 5: Use in Other Microservices

1. Add to microservice's `composer.json`:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/oureedu/laravel-sqs-messaging.git"
        }
    ],
    "require": {
        "oureedu/laravel-sqs-messaging": "dev-main"
    }
}
```

2. Install:
```bash
composer require oureedu/laravel-sqs-messaging:dev-main
```

3. Configure:
```bash
php artisan vendor:publish --provider="OurEdu\SqsMessaging\SqsMessagingServiceProvider"
php artisan migrate
php artisan sqs:ensure
```

## 🎯 Recommended Approach

1. **Create new branch** for package development
2. **Copy all files** and update namespaces
3. **Test locally** in current project
4. **Create new Git repository** for the package
5. **Push package** to repository
6. **Test in one microservice** first
7. **Roll out** to all microservices

## 📝 Files Checklist

- [ ] All 7 core classes copied and namespaces updated
- [ ] All 10 commands copied and namespaces updated  
- [ ] All 4 config files copied
- [ ] Migration file copied
- [ ] Service Provider created (✅ Done)
- [ ] Composer.json created (✅ Done)
- [ ] README created (✅ Done)
- [ ] Package tested locally
- [ ] Package pushed to Git
- [ ] Used in one microservice

## 🚀 Next Steps

1. Run the copy script
2. Update namespaces
3. Test locally
4. Push to Git
5. Install in microservice

See `MIGRATION_GUIDE.md` for detailed instructions.

