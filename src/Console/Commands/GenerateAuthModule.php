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
        $this->packageStubPath = __DIR__ . '/../../_stubs/AuthModule';
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

        // $includeRoles = true;
        // Ask about roles and permissions
        $includeRoles = !$this->option('skip-roles') &&
            $this->confirm('Do you want to add roles and permissions management?', true);

        // Ask about email verification
        $includeEmailVerification = !$this->option('skip-email-verification') &&
            $this->confirm('Do you want to enable email verification?', true);

        if (!$this->runRequiredCommand('install:api')) {
            return self::FAILURE;
        }

        // Copy Authentication files
        $this->copyAuthenticationFiles($includeEmailVerification);

        // Copy User Management files
        $this->copyUserManagementFiles();

        // Copy Roles & Permissions files if requested
        if ($includeRoles) {
            $this->installAndConfigureSpatiePackage();
            $this->copyRolesAndPermissionsFiles();
        }

        // Update User model
        $this->updateUserModel($includeRoles, $includeEmailVerification);

        // Update bootstrap/app.php
        $this->updateBootstrapApp($includeRoles);

        $this->newLine();
        $this->info('✅ Authentication system generated successfully!');
        $this->newLine();

        $this->displayNextSteps($includeRoles, $includeEmailVerification);

        return Command::SUCCESS;
    }

    protected function copyAuthenticationFiles($includeEmailVerification): void
    {
        $this->info('📝 Generating Authentication files...');

        $files = [
            'Middleware/Cors' => 'app/Http/Middleware/Cors.php',

            'Services/AuthService' => 'app/Services/AuthService.php',
            'Services/Auth/PasswordService' => 'app/Services/Auth/PasswordService.php',

            'Requests/LoginRequest' => 'app/Http/Requests/Auth/LoginRequest.php',
            'Requests/RegisterRequest' => 'app/Http/Requests/Auth/RegisterRequest.php',
            'Requests/ForgotPasswordRequest' => 'app/Http/Requests/Auth/ForgotPasswordRequest.php',
            'Requests/ResetPasswordRequest' => 'app/Http/Requests/Auth/ResetPasswordRequest.php',

            'resources/views/emails/reset_password_mail.blade' => 'resources/views/emails/reset_password_mail.blade.php',

            ...($includeEmailVerification ? [
                'Controllers/AuthController-ev' => 'app/Http/Controllers/AuthController.php',
                'routes/auth-ev' => 'routes/api/auth.php',
            ] : [
                'Controllers/AuthController' => 'app/Http/Controllers/AuthController.php',
                'routes/auth' => 'routes/api/auth.php'
            ]),

            'Mail/PasswordResetEmail' => 'app/Mail/PasswordResetEmail.php',
            'Mail/UserAccountCreateMail' => 'app/Mail/UserAccountCreateMail.php',

            ...($includeEmailVerification ? [
                'Services/Auth/VerificationService' => 'app/Services/Auth/VerificationService.php',
                'Mail/VerifyEmailMail' => 'app/Mail/VerifyEmailMail.php',
                'resources/views/emails/verify_email_mail.blade' => 'resources/views/emails/verify_email_mail.blade.php',
            ] : []),
        ];

        $this->copyFiles($files, 'Authentication');
    }

    protected function copyUserManagementFiles(): void
    {
        $this->info('📝 Generating User Management files...');

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

        $this->copyFiles($files, 'User Management');
    }

    protected function copyRolesAndPermissionsFiles(): void
    {
        $this->info('📝 Generating Roles & Permissions files...');

        $files = [
            'Controllers/RoleController' => 'app/Http/Controllers/RoleController.php',
            'Controllers/PermissionController' => 'app/Http/Controllers/PermissionController.php',

            'Utils/PermissionsData' => 'app/Utils/PermissionsData.php',
            'Services/RoleService' => 'app/Services/RoleService.php',
            'Services/PermissionService' => 'app/Services/PermissionService.php',

            'Requests/UpsertRoleRequest' => 'app/Http/Requests/Role/UpsertRoleRequest.php',
            'Requests/StorePermissionRequest' => 'app/Http/Requests/Permission/StorePermissionRequest.php',
            'Requests/UpdatePermissionRequest' => 'app/Http/Requests/Permission/UpdatePermissionRequest.php',
            'Requests/AssignPermissionToRoleRequest' => 'app/Http/Requests/Permission/AssignPermissionToRoleRequest.php',
            'Requests/AssignPermissionToUserRequest' => 'app/Http/Requests/Permission/AssignPermissionToUserRequest.php',

            'Resources/RoleResource' => 'app/Http/Resources/RoleResource.php',
            'Resources/RoleCollection' => 'app/Http/Resources/RoleCollection.php',
            'Resources/PermissionResource' => 'app/Http/Resources/PermissionResource.php',
            'Resources/PermissionCollection' => 'app/Http/Resources/PermissionCollection.php',

            'seeders/PermissionSeeder' => 'database/seeders/PermissionSeeder.php',
            'config/permission' => 'config/permission.php',

            'routes/access-control' => 'routes/api/access-control.php',

            'Models/Role' => 'app/Models/Role.php',
            'Models/Permission' => 'app/Models/Permission.php',
        ];

        $this->copyFiles($files, 'Roles & Permissions');
    }

    protected function copyFiles(array $files, string $component): void
    {
        foreach ($files as $source => $destination) {
            // Determine source extension based on file type
            $sourceExtension = '.stub';

            // For blade files, the stub should have .blade.stub extension
            if (str_ends_with($destination, '.blade.php')) {
                $sourcePath = $this->packageStubPath . '/' . $source . '.stub';
            } else {
                $sourcePath = $this->packageStubPath . '/' . $source . '.stub';
            }

            $destinationPath = $this->basePath . '/' . $destination;

            if (!File::exists($sourcePath)) {
                $this->warn("⚠️  Source file not found: {$sourcePath}");

                continue;
            }

            if (File::exists($destinationPath) && !$this->option('force')) {
                if (!$this->confirm("File already exists: {$destination}. Do you want to replace it?", false)) {
                    $this->line("⏭️  Skipped: {$destination}");

                    continue;
                }
            }

            $directory = dirname($destinationPath);
            if (!File::isDirectory($directory)) {
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
        $composerJson = json_decode(File::get($composerJsonPath), true);

        $freshInstall = false;

        if (!isset($composerJson['require']['spatie/laravel-permission'])) {
            $this->info('Running: composer require spatie/laravel-permission');
            exec('composer require spatie/laravel-permission 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                $this->error('❌ Failed to install Spatie Laravel Permission');
                $this->warn('Please run manually: composer require spatie/laravel-permission');
                return;
            }

            $freshInstall = true;
            $this->info('✅ Spatie Laravel Permission installed');
        } else {
            $this->line('✅ Spatie Laravel Permission already installed');
        }

        /**
         * 🔴 THIS IS THE MISSING PART 🔴
         * Laravel must rediscover providers
         */
        if ($freshInstall) {
            $this->info('Refreshing Composer autoload...');
            exec('composer dump-autoload 2>&1');

            $this->info('Running package discovery...');
            Artisan::call('package:discover', [], $this->getOutput());
        }

        // Publish config + migrations
        $this->info('Publishing Spatie config and migrations...');

        exec(
            'php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --force 2>&1',
            $output,
            $exitCode
        );

        if ($exitCode !== 0) {
            $this->error('❌ Failed to publish Spatie resources');
            $this->line(implode("\n", $output));
            return;
        }

        $this->info('✅ Spatie config and migrations published');


        // Clear caches
        $this->info('Clearing caches...');
        Artisan::call('optimize:clear', [], $this->getOutput());

        $this->info('✅ Spatie Permission fully configured');
    }

    protected function updateUserModel(bool $includeRoles, bool $includeEmailVerification): void
    {
        $userModelPath = app_path('Models/User.php');

        if (!File::exists($userModelPath)) {
            $this->warn('⚠️  User model not found at: ' . $userModelPath);

            return;
        }

        $this->info('📝 Updating User model...');

        $content = File::get($userModelPath);
        $modified = false;

        // Add MustVerifyEmail interface
        if ($includeEmailVerification) {
            if (!str_contains($content, 'MustVerifyEmail')) {
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
            if (!str_contains($content, 'use Spatie\Permission\Traits\HasRoles;')) {
                $content = str_replace(
                    'use Illuminate\Notifications\Notifiable;',
                    "use Illuminate\Notifications\Notifiable;\nuse Spatie\Permission\Traits\HasRoles;",
                    $content
                );

                // Add trait usage in class
                if (preg_match('/class User.*?\{.*?use ([^;]+);/s', $content, $matches)) {
                    $traits = $matches[1];
                    if (!str_contains($traits, 'HasRoles')) {
                        $newTraits = trim($traits) . ', HasRoles';
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

    protected function updateBootstrapApp(bool $includeRoles): void
    {
        $bootstrapPath = base_path('bootstrap/app.php');

        if (!File::exists($bootstrapPath)) {
            $this->warn('⚠️  bootstrap/app.php not found');

            return;
        }

        $this->info('📝 Updating bootstrap/app.php...');

        $content = File::get($bootstrapPath);
        $modified = false;

        // Handle withMiddleware section
        if (preg_match('/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{(.*?)\}\)/s', $content, $matches)) {
            $middlewareContent = $matches[1];
            $updatedMiddlewareContent = $middlewareContent;

            // Add statefulApi if not exists
            if (!str_contains($middlewareContent, '$middleware->statefulApi()')) {
                $updatedMiddlewareContent = "\n        \$middleware->statefulApi();";
                $modified = true;
                $this->line('✅ Added statefulApi middleware');
            }

            // Check if alias method exists
            if (!str_contains($middlewareContent, '$middleware->alias(')) {
                // Build the alias array
                $aliases = "\n        \$middleware->alias([\n";
                $aliases .= "            'cors' => App\Http\Middleware\Cors::class,\n";

                if ($includeRoles) {
                    $aliases .= "\n            // spatie permission middleware\n";
                    $aliases .= "            'role' => \\Spatie\\Permission\\Middleware\\RoleMiddleware::class,\n";
                    $aliases .= "            'permission' => \\Spatie\\Permission\\Middleware\\PermissionMiddleware::class,\n";
                    $aliases .= "            'role_or_permission' => \\Spatie\\Permission\\Middleware\\RoleOrPermissionMiddleware::class,\n";
                }

                $aliases .= '        ]);';
                $updatedMiddlewareContent .= $aliases;
                $modified = true;
                $this->line('✅ Added middleware aliases');
            } else {
                // Alias exists, check and add missing ones
                if (!str_contains($middlewareContent, "'cors'")) {
                    $updatedMiddlewareContent = preg_replace(
                        '/(\$middleware->alias\(\[)/s',
                        "$1\n            'cors' => App\Http\Middleware\Cors::class,",
                        $updatedMiddlewareContent
                    );
                    $modified = true;
                }

                if ($includeRoles) {
                    if (!str_contains($middlewareContent, "'role'")) {
                        $updatedMiddlewareContent = preg_replace(
                            '/(\$middleware->alias\(\[[^\]]*)/s',
                            "$1\n            'role' => \\Spatie\\Permission\\Middleware\\RoleMiddleware::class,",
                            $updatedMiddlewareContent
                        );
                        $modified = true;
                    }
                    if (!str_contains($middlewareContent, "'permission'")) {
                        $updatedMiddlewareContent = preg_replace(
                            '/(\$middleware->alias\(\[[^\]]*)/s',
                            "$1\n            'permission' => \\Spatie\\Permission\\Middleware\\PermissionMiddleware::class,",
                            $updatedMiddlewareContent
                        );
                        $modified = true;
                    }
                    if (!str_contains($middlewareContent, "'role_or_permission'")) {
                        $updatedMiddlewareContent = preg_replace(
                            '/(\$middleware->alias\(\[[^\]]*)/s',
                            "$1\n            'role_or_permission' => \\Spatie\\Permission\\Middleware\\RoleOrPermissionMiddleware::class,",
                            $updatedMiddlewareContent
                        );
                        $modified = true;
                    }
                }
            }

            // Replace the middleware content
            $content = preg_replace(
                '/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{.*?\}\)/s',
                "->withMiddleware(function (Middleware \$middleware): void {{$updatedMiddlewareContent}\n    })",
                $content
            );
        }

        if ($modified) {
            File::put($bootstrapPath, $content);
            $this->info('✅ bootstrap/app.php updated successfully');
        } else {
            $this->line('ℹ️  bootstrap/app.php already up to date');
        }
    }

    protected function displayNextSteps(bool $includeRoles, bool $includeEmailVerification): void
    {
        $this->info('📋 Next Steps:');
        $this->line('');
        $this->line('1. Add route files to your routes/api.php or web.php:');
        $this->line('   Route::middleware(\'api\')->group(base_path(\'routes/api/auth.php\'));');
        $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/user.php\'));');

        if ($includeRoles) {
            $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/role.php\'));');
            $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/permission.php\'));');
        }

        $this->line('');
        $this->line('2. Run migrations:');
        $this->line('   php artisan migrate');

        if (!$includeRoles) {
            $this->line('');
            $this->line('3. Install Laravel Sanctum if not already installed:');
            $this->line('   composer require laravel/sanctum');
            $this->line('   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"');
            $this->line('   php artisan migrate');
        }

        $this->line('');
        $step = $includeRoles ? '3' : '4';
        $this->line($step . '. Update your .env file with mail configuration for password reset' . ($includeEmailVerification ? ' and email verification' : ''));
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