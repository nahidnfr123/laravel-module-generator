<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Exception\CommandNotFoundException;

class GenerateAuthModule extends Command
{
    protected $signature = 'auth:generate
                            {--force : Overwrite existing files without confirmation}
                            {--skip-roles : Skip roles and permissions setup}
                            {--skip-email-verification : Skip email verification setup}';

    protected $description = 'Generate authentication, user management, and optionally roles & permissions';

    protected $basePath;

    protected $packageStubPath;

    public function __construct()
    {
        parent::__construct();
        $this->packageStubPath = __DIR__.'/../../stubs/auth';
    }

    /**
     * @throws FileNotFoundException
     * @throws \JsonException
     */
    public function handle()
    {
        $this->basePath = base_path();

        $this->info('🚀 Starting Authentication & User Management Generation...');
        $this->newLine();

        // Ask about roles and permissions
        $includeRoles = ! $this->option('skip-roles') &&
            $this->confirm('Do you want to add roles and permissions management?', true);

        // Ask about email verification
        $includeEmailVerification = ! $this->option('skip-email-verification') &&
            $this->confirm('Do you want to enable email verification?', true);

        if (! $this->runRequiredCommand('install:api')) {
            return self::FAILURE;
        }

        // Copy Authentication files
        $this->copyAuthenticationFiles();

        // Copy User Management files
        $this->copyUserManagementFiles();

        // Copy Roles & Permissions files if requested
        if ($includeRoles) {
            $this->installAndConfigureSpatiePackage();
            $this->copyRolesAndPermissionsFiles();
        }

        // Update User model
        $this->updateUserModel($includeRoles, $includeEmailVerification);

        $this->newLine();
        $this->info('✅ Authentication system generated successfully!');
        $this->newLine();

        $this->displayNextSteps($includeRoles, $includeEmailVerification);

        return Command::SUCCESS;
    }

    protected function copyAuthenticationFiles(): void
    {
        $this->info('📝 Generating Authentication files...');

        $files = [
            'Middleware/Cors.php' => 'app/Http/Middleware/Cors.php',
            'Traits/ApiResponseTrait.php' => 'app/Traits/ApiResponseTrait.php',
            'Traits/HandlesPagination.php' => 'app/Traits/HandlesPagination.php',
            'Traits/HasSlugModelBinding.php' => 'app/Traits/HasSlugModelBinding.php',
            'Traits/HasSlug/HasSlug.php' => 'app/Traits/HasSlug/HasSlug.php',
            'Traits/HasSlug/SlugOptions.php' => 'app/Traits/HasSlug/SlugOptions.php',
            'Traits/HasSlug/Exceptions/InvalidOption.php' => 'app/Traits/HasSlug/Exceptions/InvalidOption.php',
            'Controllers/AuthController.php' => 'app/Http/Controllers/AuthController.php',
            'Services/AuthService.php' => 'app/Services/AuthService.php',
            'Requests/LoginRequest.php' => 'app/Http/Requests/Auth/LoginRequest.php',
            'Requests/RegisterRequest.php' => 'app/Http/Requests/Auth/RegisterRequest.php',
            'Requests/ForgotPasswordRequest.php' => 'app/Http/Requests/Auth/ForgotPasswordRequest.php',
            'Requests/ResetPasswordRequest.php' => 'app/Http/Requests/Auth/ResetPasswordRequest.php',
            'resources/views/emails/reset_password_mail.blade.php' => 'resources/views/emails/reset_password_mail.blade.php',
            'resources/views/emails/verify_email_mail.blade.php' => 'resources/views/emails/verify_email_mail.blade.php',
            'routes/auth.php' => 'routes/auth.php',
        ];

        $this->copyFiles($files, 'Authentication');
    }

    protected function copyUserManagementFiles(): void
    {
        $this->info('📝 Generating User Management files...');

        $files = [
            'Controllers/UserController.php' => 'app/Http/Controllers/UserController.php',
            'Services/UserService.php' => 'app/Services/UserService.php',
            'Requests/StoreUserRequest.php' => 'app/Http/Requests/User/StoreUserRequest.php',
            'Requests/UpdateUserRequest.php' => 'app/Http/Requests/User/UpdateUserRequest.php',
            'Resources/UserResource.php' => 'app/Http/Resources/UserResource.php',
            'Resources/UserCollection.php' => 'app/Http/Resources/UserCollection.php',
        ];

        $this->copyFiles($files, 'User Management');
    }

    protected function copyRolesAndPermissionsFiles(): void
    {
        $this->info('📝 Generating Roles & Permissions files...');

        $files = [
            'Controllers/RoleController.php' => 'app/Http/Controllers/RoleController.php',
            'Controllers/PermissionController.php' => 'app/Http/Controllers/PermissionController.php',
            'Services/RoleService.php' => 'app/Services/RoleService.php',
            'Services/PermissionService.php' => 'app/Services/PermissionService.php',
            'Requests/UpsertRoleRequest.php' => 'app/Http/Requests/Role/UpsertRoleRequest.php',
            'Requests/StorePermissionRequest.php' => 'app/Http/Requests/Permission/StorePermissionRequest.php',
            'Requests/UpdatePermissionRequest.php' => 'app/Http/Requests/Permission/UpdatePermissionRequest.php',
            'Requests/AssignPermissionToRoleRequest.php' => 'app/Http/Requests/Permission/AssignPermissionToRoleRequest.php',
            'Requests/AssignPermissionToUserRequest.php' => 'app/Http/Requests/Permission/AssignPermissionToUserRequest.php',
            'Resources/RoleResource.php' => 'app/Http/Resources/RoleResource.php',
            'Resources/RoleCollection.php' => 'app/Http/Resources/RoleCollection.php',
            'Resources/PermissionResource.php' => 'app/Http/Resources/PermissionResource.php',
            'Resources/PermissionCollection.php' => 'app/Http/Resources/PermissionCollection.php',
            'config/permission.php' => 'config/permission.php',
        ];

        $this->copyFiles($files, 'Roles & Permissions');
    }

    protected function copyFiles(array $files, string $component): void
    {
        foreach ($files as $source => $destination) {
            $sourcePath = $this->packageStubPath.'/'.$source;
            $destinationPath = $this->basePath.'/'.$destination;

            if (! File::exists($sourcePath)) {
                $this->warn("⚠️  Source file not found: {$source}");

                continue;
            }

            if (File::exists($destinationPath) && ! $this->option('force')) {
                if (! $this->confirm("File already exists: {$destination}. Do you want to replace it?", false)) {
                    $this->line("⏭️  Skipped: {$destination}");

                    continue;
                }
            }

            $directory = dirname($destinationPath);
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::copy($sourcePath, $destinationPath);
            $this->line("✅ Created: {$destination}");
        }
    }

    /**
     * @throws FileNotFoundException
     * @throws \JsonException
     */
    protected function installAndConfigureSpatiePackage(): void
    {
        $this->info('📦 Installing and configuring Spatie Laravel Permission package...');

        $composerJsonPath = base_path('composer.json');
        $composerJson = json_decode(File::get($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);

        if (isset($composerJson['require']['spatie/laravel-permission'])) {
            $this->line('✅ Spatie Laravel Permission already installed');
        } else {
            $this->info('Running: composer require spatie/laravel-permission');
            $this->line('Please wait...');

            exec('composer require spatie/laravel-permission 2>&1', $output, $returnCode);

            if ($returnCode === 0) {
                $this->info('✅ Spatie Laravel Permission installed successfully');
            } else {
                $this->error('❌ Failed to install Spatie Laravel Permission');
                $this->warn('Please run manually: composer require spatie/laravel-permission');

                return;
            }
        }

        // Publish config and migrations
        $this->info('Publishing Spatie configuration and migrations...');
        Artisan::call('vendor:publish', [
            '--provider' => 'Spatie\Permission\PermissionServiceProvider',
        ]);
        $this->line('✅ Spatie files published');

        // Clear optimization cache
        $this->info('Clearing optimization cache...');
        Artisan::call('optimize:clear');
        $this->line('✅ Cache cleared');
    }

    protected function updateUserModel(bool $includeRoles, bool $includeEmailVerification): void
    {
        $userModelPath = app_path('Models/User.php');

        if (! File::exists($userModelPath)) {
            $this->warn('⚠️  User model not found at: '.$userModelPath);

            return;
        }

        $this->info('📝 Updating User model...');

        $content = File::get($userModelPath);
        $modified = false;

        // Add MustVerifyEmail interface
        if ($includeEmailVerification) {
            if (! str_contains($content, 'MustVerifyEmail')) {
                $content = str_replace(
                    'use Illuminate\Foundation\Auth\User as Authenticatable;',
                    "use Illuminate\Contracts\Auth\MustVerifyEmail;\nuse Illuminate\Foundation\Auth\User as Authenticatable;",
                    $content
                );

                // Update class declaration
                $content = preg_replace(
                    '/class User extends Authenticatable/',
                    'class User extends Authenticatable implements MustVerifyEmail',
                    $content
                );

                $modified = true;
                $this->line('✅ Added MustVerifyEmail interface');
            }
        }

        // Add HasRoles trait
        if ($includeRoles) {
            if (! str_contains($content, 'use Spatie\Permission\Traits\HasRoles;')) {
                $content = str_replace(
                    'use Illuminate\Notifications\Notifiable;',
                    "use Illuminate\Notifications\Notifiable;\nuse Spatie\Permission\Traits\HasRoles;",
                    $content
                );

                // Add trait usage in class
                if (preg_match('/class User.*?\{.*?use ([^;]+);/s', $content, $matches)) {
                    $traits = $matches[1];
                    if (! str_contains($traits, 'HasRoles')) {
                        $newTraits = trim($traits).', HasRoles';
                        $content = str_replace(
                            "use {$traits};",
                            "use {$newTraits};",
                            $content
                        );
                    }
                }

                $modified = true;
                $this->line('✅ Added HasRoles trait');
            }
        }

        if ($modified) {
            File::put($userModelPath, $content);
            $this->info('✅ User model updated successfully');
        } else {
            $this->line('ℹ️  User model already up to date');
        }
    }

    protected function displayNextSteps(bool $includeRoles, bool $includeEmailVerification): void
    {
        $this->info('📋 Next Steps:');
        $this->line('');
        $this->line('1. Add route files to your routes/api.php or web.php:');
        $this->line('   Route::middleware(\'api\')->group(base_path(\'routes/auth.php\'));');
        $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/user.php\'));');

        if ($includeRoles) {
            $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/role.php\'));');
            $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/permission.php\'));');
        }

        $this->line('');
        $this->line('2. Run migrations:');
        $this->line('   php artisan migrate');

        if (! $includeRoles) {
            $this->line('');
            $this->line('3. Install Laravel Sanctum if not already installed:');
            $this->line('   composer require laravel/sanctum');
            $this->line('   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"');
            $this->line('   php artisan migrate');
        }

        $this->line('');
        $step = $includeRoles ? '3' : '4';
        $this->line($step.'. Update your .env file with mail configuration for password reset'.($includeEmailVerification ? ' and email verification' : ''));
        $this->line('   MAIL_MAILER=smtp');
        $this->line('   MAIL_HOST=your-mail-host');
        $this->line('   MAIL_PORT=587');
        $this->line('   MAIL_USERNAME=your-username');
        $this->line('   MAIL_PASSWORD=your-password');
        $this->line('   MAIL_ENCRYPTION=tls');
        $this->line('   MAIL_FROM_ADDRESS=noreply@yourapp.com');
        $this->line('   MAIL_FROM_NAME="${APP_NAME}"');

        if ($includeEmailVerification) {
            $this->line('');
            $this->line('4. Email verification has been enabled in your User model');
            $this->line('   Users will need to verify their email before accessing protected routes');
        }

        $this->line('');
        $this->info('🎉 You\'re all set! Happy coding!');
    }

    protected function runRequiredCommand(string $command, array $arguments = []): bool
    {
        $this->info(sprintf('Running %s...', $command));

        try {
            $exitCode = Artisan::call($command, $arguments, $this->getOutput());
        } catch (CommandNotFoundException $e) {
            $this->error(sprintf('Command "%s" is not available in this application.', $command));

            return false;
        }

        if ($exitCode !== 0) {
            $this->error(sprintf('Command "%s" exited with code %s.', $command, $exitCode));

            return false;
        }

        return true;
    }
}
