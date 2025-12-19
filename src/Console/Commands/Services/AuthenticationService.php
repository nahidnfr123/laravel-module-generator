<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\Services;

class AuthenticationService extends BaseAuthModuleService
{
    public function generate(): void
    {
        $this->copyAuthenticationFiles();
        $this->copyUserManagementFiles();
    }

    protected function copyAuthenticationFiles(): void
    {
        $path = config('module-generator.models_path');
        $directory = dirname($path);

        if (!file_exists($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        $this->command->info('📝 Generating Authentication files...');

        $files = [
            'AuthModule.postman_collection' => $directory . '/AuthModule.postman_collection.json',
            'Services/AuthService' => 'app/Services/AuthService.php',
            'Services/Auth/PasswordService' => 'app/Services/Auth/PasswordService.php',

            'Requests/LoginRequest' => 'app/Http/Requests/Auth/LoginRequest.php',
            'Requests/RegisterRequest' => 'app/Http/Requests/Auth/RegisterRequest.php',
            'Requests/ForgotPasswordRequest' => 'app/Http/Requests/Auth/ForgotPasswordRequest.php',
            'Requests/ResetPasswordRequest' => 'app/Http/Requests/Auth/ResetPasswordRequest.php',

            'Controllers/AuthController' => 'app/Http/Controllers/AuthController.php',
            'routes/auth' => 'routes/api/auth.php',

            'Mail/PasswordResetEmail' => 'app/Mail/PasswordResetEmail.php',
            'Mail/UserAccountCreateMail' => 'app/Mail/UserAccountCreateMail.php',
            'resources/views/emails/reset_password_mail.blade' => 'resources/views/emails/reset_password_mail.blade.php',
        ];

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
}