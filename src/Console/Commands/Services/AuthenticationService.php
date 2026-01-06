<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\Services;

class AuthenticationService extends BaseAuthModuleService
{
    public function generate($apiAuthDriver): void
    {
        $this->apiDriver = $apiAuthDriver ?? 'sanctum';
        if ($this->apiDriver === 'sanctum') {
            $this->installSanctum();
        } elseif ($this->apiDriver === 'passport') {
            $this->installPassport();
        }
        $this->copyAuthenticationFiles();
        $this->copyUserManagementFiles();
    }

    protected function copyAuthenticationFiles(): void
    {
        $path = config('module-generator.models_path');
        $directory = dirname($path);

        if (! file_exists($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        $this->command->info('📝 Generating Authentication files...');

        $files = [
            'AuthModule.postman_collection' => $directory.'/AuthModule.postman_collection.json',
            'Services/Auth/PasswordService' => 'app/Services/Auth/PasswordService.php',

            'Requests/LoginRequest' => 'app/Http/Requests/Auth/LoginRequest.php',
            'Requests/RegisterRequest' => 'app/Http/Requests/Auth/RegisterRequest.php',
            'Requests/ForgotPasswordRequest' => 'app/Http/Requests/Auth/ForgotPasswordRequest.php',
            'Requests/ResetPasswordRequest' => 'app/Http/Requests/Auth/ResetPasswordRequest.php',

            'routes/auth' => 'routes/api/auth.php',

            'Mail/PasswordResetEmail' => 'app/Mail/PasswordResetEmail.php',
            'Mail/UserAccountCreateMail' => 'app/Mail/UserAccountCreateMail.php',
            'resources/views/emails/reset_password_mail.blade' => 'resources/views/emails/reset_password_mail.blade.php',
        ];

        if ($this->apiDriver === 'sanctum') {
            $files = array_merge($files, [
                'Services/AuthService' => 'app/Services/AuthService.php',
                'Controllers/AuthController' => 'app/Http/Controllers/Auth/AuthController.php',
            ]);
        } elseif ($this->apiDriver === 'passport') {
            $files = array_merge($files, [
                'Controllers/AuthController_passport' => 'app/Http/Controllers/Auth/AuthController.php',
                'Requests/RefreshTokenRequest' => 'app/Http/Requests/Auth/RefreshTokenRequest.php',
                'Services/AuthService_passport' => 'app/Services/AuthService.php',
            ]);
        }

        $this->copyFiles($files);
    }

    protected function copyUserManagementFiles(): void
    {
        $this->command->info('📝 Generating User Management files...');

        $files = [
            'Controllers/UserController' => 'app/Http/Controllers/UserController.php',
            'Services/UserService' => 'app/Services/UserService.php',
            'seeders/UserTableSeeder' => 'database/seeders/UserTableSeeder.php',

            'Requests/StoreUserRequest' => 'app/Http/Requests/User/StoreUserRequest.php',
            'Requests/UpdateUserRequest' => 'app/Http/Requests/User/UpdateUserRequest.php',
            'Requests/ChangePasswordRequest' => 'app/Http/Requests/User/ChangePasswordRequest.php',

            'Resources/UserProfileResource' => 'app/Http/Resources/UserProfileResource.php',
            'Resources/UserResource' => 'app/Http/Resources/UserResource.php',
            'Resources/UserCollection' => 'app/Http/Resources/UserCollection.php',
        ];

        $this->copyFiles($files);
    }

    protected function installPassport(): void
    {
        //        $this->command->info('📦 Installing Laravel Passport...');
        //
        //        // Install package
        //        $this->run('composer require laravel/passport');
        //
        //        // Publish Passport configuration and migrations
        //        $this->command->info('📝 Publishing Passport assets...');
        //        $this->run('php artisan vendor:publish --tag=passport-migrations');
        //        $this->run('php artisan vendor:publish --tag=passport-config');
        //
        //        // Run migrations to create OAuth tables
        //        $this->command->info('🔄 Running Passport migrations...');
        //        $this->run('php artisan migrate --force');
        //
        //        // Clear cached commands/providers
        //        $this->run('php artisan optimize:clear');
        //
        //        // Install Passport (creates encryption keys and OAuth clients)
        //        // MUST use shell execution to ensure fresh application bootstrap
        //        $this->command->info('🔑 Installing Passport keys and clients...');
        //        $this->runShell('php artisan passport:install --force');

        // Update User model
        $this->updateUserModel([
            [
                'type' => 'trait',
                'trait' => 'HasApiTokens',
                'use_statement' => 'use Laravel\\Passport\\HasApiTokens;',
            ],
        ]);

        $this->command->info('✅ Laravel Passport installed successfully!');
    }

    protected function installSanctum(): void
    {
        $this->command->info('📦 Installing Laravel Sanctum...');

        $this->run('composer require laravel/sanctum');
        $this->run('php artisan vendor:publish --provider="Laravel\\Sanctum\\SanctumServiceProvider"');

        $this->updateUserModel([
            [
                'type' => 'trait',
                'trait' => 'HasApiTokens',
                'use_statement' => 'use Laravel\\Sanctum\\HasApiTokens;',
            ],
        ]);
    }
}
