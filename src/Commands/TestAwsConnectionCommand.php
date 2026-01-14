<?php

namespace OurEdu\SqsMessaging\Commands;

use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;

class TestAwsConnectionCommand extends Command
{
    protected $signature = 'sqs:test:connection';
    protected $description = 'Test AWS SQS connection and credentials';

    public function handle(): int
    {
        $this->info('Testing AWS SQS Connection...');
        $this->line('');

        // Get credentials
        $key = config('aws.key', env('AWS_SQS_ACCESS_KEY_ID'));
        $secret = config('aws.secret', env('AWS_SQS_SECRET_ACCESS_KEY'));
        $region = config('aws.region', env('AWS_DEFAULT_REGION', 'us-east-2'));

        // Display config (without showing full secret)
        $this->line('Configuration:');
        $this->line("  Region: {$region}");
        $this->line("  Access Key ID: " . ($key ? substr($key, 0, 20) . '...' : 'NOT SET'));
        $this->line("  Secret Key: " . ($secret ? '***SET*** (' . strlen($secret) . ' chars)' : 'NOT SET'));
        $this->line('');
        
        // Validate Access Key format
        if ($key && !preg_match('/^AKIA[A-Z0-9]{16}$/', $key)) {
            $this->warn('⚠️  WARNING: Access Key ID format looks unusual!');
            $this->warn('   AWS Access Keys MUST start with "AKIA" and be 20 characters');
            $this->warn('   Your key starts with: ' . substr($key, 0, 4));
            $this->warn('   Length: ' . strlen($key) . ' characters');
            $this->line('');
            $this->error('🔴 CREDENTIALS SWAPPED?');
            $this->line('   Your Access Key ID field has: ' . substr($key, 0, 20) . '...');
            $this->line('   If this starts with "rhrz" or similar, it might be the SECRET KEY!');
            $this->line('   Check your .env file - credentials might be swapped.');
            $this->line('');
            $this->line('   Correct format:');
            $this->line('   AWS_SQS_ACCESS_KEY_ID=AKIA...');
            $this->line('   AWS_SQS_SECRET_ACCESS_KEY=rhrz...');
            $this->line('');
        }

        if (!$key || !$secret) {
            $this->error('❌ AWS credentials are missing in .env file!');
            $this->line('');
            $this->line('Please add to your .env file:');
            $this->line('AWS_SQS_ACCESS_KEY_ID=your-key-here');
            $this->line('AWS_SQS_SECRET_ACCESS_KEY=your-secret-here');
            $this->line('AWS_DEFAULT_REGION=your-region-here');
            return Command::FAILURE;
        }

        try {
            $this->info('Creating SQS client...');
            
            $client = new SqsClient([
                'region' => $region,
                'version' => 'latest',
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
            ]);

            $this->info('✅ SQS client created');
            $this->line('');

            // Test connection by listing queues
            $this->info("Testing connection to region: {$region}...");
            $result = $client->listQueues();
            
            $queues = $result->get('QueueUrls') ?? [];
            
            $this->info('✅ Connection successful!');
            $this->line('');
            $this->line("Found " . count($queues) . " queue(s) in this region:");
            
            if (empty($queues)) {
                $this->warn('  No queues found in this region');
            } else {
                foreach ($queues as $queueUrl) {
                    $this->line("  - {$queueUrl}");
                }
            }

            return Command::SUCCESS;
        } catch (\Aws\Exception\CredentialsException $e) {
            $this->error('❌ Credentials Error: ' . $e->getMessage());
            $this->line('');
            $this->line('Possible issues:');
            $this->line('  1. AWS_SQS_ACCESS_KEY_ID is incorrect');
            $this->line('  2. AWS_SQS_SECRET_ACCESS_KEY is incorrect');
            $this->line('  3. Credentials have been revoked/deleted');
            $this->line('');
            $this->line('Please check your .env file and AWS IAM console.');
            return Command::FAILURE;
        } catch (\Aws\Exception\AwsException $e) {
            $this->error('❌ AWS Error: ' . $e->getAwsErrorCode());
            $this->error('Message: ' . $e->getAwsErrorMessage());
            $this->line('');
            
            if ($e->getAwsErrorCode() === 'InvalidClientTokenId') {
                $this->line('The AWS credentials are invalid or expired.');
                $this->line('');
                $this->line('🔴 PRIMARY ISSUE: Wrong credentials format!');
                $this->line('   Your Access Key starts with: ' . substr($key, 0, 4));
                $this->line('   AWS Access Keys MUST start with "AKIA" or "ASIA"');
                $this->line('');
                $this->line('Please check:');
                $this->line('  1. Get correct Access Key from AWS IAM Console');
                $this->line('  2. Access Key ID should start with "AKIA..." (20 chars)');
                $this->line('  3. Update .env file with correct credentials');
                $this->line('  4. After fixing credentials, ensure user has SQS permissions');
            } elseif ($e->getAwsErrorCode() === 'AccessDenied') {
                $this->line('🔴 PERMISSIONS ISSUE: Credentials are valid but no SQS permissions');
                $this->line('');
                $this->line('Fix: Add SQS permissions to IAM user');
                $this->line('  1. AWS Console → IAM → Users → Select user');
                $this->line('  2. Add permissions → Attach policy');
                $this->line('  3. Search: "AmazonSQSFullAccess" → Attach');
            }
            
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->line('');
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

