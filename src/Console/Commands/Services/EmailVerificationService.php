<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\Services;

class EmailVerificationService extends BaseAuthModuleService
{
    public function generate(): void
    {
        $this->copyEmailVerificationFiles();
        $this->createMigration();
        $this->copyAuthControllerWithVerification();
        $this->updateUserModelForVerification();
    }

    protected function copyEmailVerificationFiles(): void
    {
        $this->command->info('📝 Generating Email Verification files...');

        $timestamp = date('Y_m_d_His');
        $files = [
            'Models/EmailVerificationToken' => 'app/Models/EmailVerificationToken.php',
            // 'migrations/create_email_verification_tokens_table' => "database/migrations/{$timestamp}_create_email_verification_tokens_table.php",
            'Mail/VerifyEmailMail' => 'app/Mail/VerifyEmailMail.php',
            'resources/views/emails/verify_email_mail.blade' => 'resources/views/emails/verify_email_mail.blade.php',
        ];

        $this->copyFiles($files);
    }

    protected function createMigration(): void
    {
        $migrationPath = database_path('migrations');

        // Check if migration already exists by pattern matching
        $existingMigrations = glob($migrationPath.'/*_create_email_verification_tokens_table.php');

        if (! empty($existingMigrations)) {
            $this->command->line('ℹ️  Social accounts migration already exists: '.basename($existingMigrations[0]));

            return;  // <-- This should stop here
        }

        $this->command->info('📝 Creating email_verification_tokens migration...');

        $migrationPath = database_path('migrations');
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_create_email_verification_tokens_table.php";
        $destination = "{$migrationPath}/{$filename}";

        if (! file_exists($migrationPath) && ! mkdir($migrationPath, 0755, true) && ! is_dir($migrationPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $migrationPath));
        }

        $stubPath = $this->getStubPath('migrations/create_email_verification_tokens_table');

        if (file_exists($stubPath.'.php')) {
            copy($stubPath.'.php', $destination);
            $this->command->line("✅ Created: {$destination}");
        } else {
            $files = [
                'migrations/create_email_verification_tokens_table' => $this->getMigrationPath(),
            ];
            $this->copyFiles($files);
        }
    }

    protected function getStubPath(string $filename): string
    {
        return __DIR__."/../../_stubs/AuthModule/migrations/{$filename}.stub";
    }

    protected function getMigrationPath(): string
    {
        $timestamp = date('Y_m_d_His');

        return "database/migrations/{$timestamp}_create_email_verification_tokens_table.php";
    }

    protected function copyAuthControllerWithVerification(): void
    {
        $this->command->info('📝 Copying AuthController with email verification...');

        $files = [
            'Controllers/AccountVerificationController' => 'app/Http/Controllers/Auth/AccountVerificationController.php',
            'routes/auth-ev' => 'routes/api/auth.php',
        ];

        $this->copyFiles($files);
    }

    protected function updateUserModelForVerification(): void
    {
        $updates = [
            [
                'type' => 'interface',
                'interface' => 'MustVerifyEmail',
                'search' => 'use Illuminate\Foundation\Auth\User as Authenticatable;',
                'replace' => "use Illuminate\Contracts\Auth\MustVerifyEmail;\nuse Illuminate\Foundation\Auth\User as Authenticatable;",
                'class_declaration' => 'class User extends Authenticatable implements MustVerifyEmail',
            ],
        ];

        $this->updateUserModel($updates);
    }
}
