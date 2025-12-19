<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\Services;

class EmailVerificationService extends BaseAuthModuleService
{
    public function generate(): void
    {
        $this->copyEmailVerificationFiles();
        $this->copyAuthControllerWithVerification();
        $this->updateUserModelForVerification();
    }

    protected function copyEmailVerificationFiles(): void
    {
        $this->command->info('📝 Generating Email Verification files...');

        $files = [
            'Models/EmailVerificationToken' => 'app/Models/EmailVerificationToken.php',
            'migrations/2025_00_00_000000_create_email_verification_tokens_table' =>
                'database/migrations/2025_00_00_000000_create_email_verification_tokens_table.php',
            'Mail/VerifyEmailMail' => 'app/Mail/VerifyEmailMail.php',
            'resources/views/emails/verify_email_mail.blade' => 'resources/views/emails/verify_email_mail.blade.php',
        ];

        $this->copyFiles($files);
    }

    protected function copyAuthControllerWithVerification(): void
    {
        $this->command->info('📝 Copying AuthController with email verification...');

        $files = [
            'Controllers/AuthController-ev' => 'app/Http/Controllers/AuthController.php',
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
            ]
        ];

        $this->updateUserModel($updates);
    }
}