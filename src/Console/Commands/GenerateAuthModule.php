<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class GenerateAuthModule extends Command
{{
    protected $signature = 'auth:generate 
                            {--force : Overwrite existing files without confirmation}
                            {--skip-roles : Skip roles and permissions setup}';

    protected $description = 'Generate authentication, user management, and optionally roles & permissions';

    protected $basePath;
    protected $packageStubPath;

    public function __construct()
    {
        parent::__construct();
        $this->packageStubPath = __DIR__ . '/../../stubs/auth';
    }

    public function handle()
    {
        $this->basePath = base_path();

        $this->info('🚀 Starting Authentication & User Management Generation...');
        $this->newLine();

        // Ask about roles and permissions
        $includeRoles = !$this->option('skip-roles') &&
            $this->confirm('Do you want to add roles and permissions management?', true);

        // Copy Authentication files
        $this->copyAuthenticationFiles();

        // Copy User Management files
        $this->copyUserManagementFiles();

        // Copy Roles & Permissions files if requested
        if ($includeRoles) {
            $this->copyRolesAndPermissionsFiles();
            $this->installSpatiePackage();
        }

        $this->newLine();
        $this->info('✅ Authentication system generated successfully!');
        $this->newLine();

        $this->displayNextSteps($includeRoles);

        return Command::SUCCESS;
    }

    protected function copyAuthenticationFiles()
    {
        $this->info('📝 Generating Authentication files...');

        $files = [
            // Controller
            'Controllers/AuthController.php' => 'app/Http/Controllers/AuthController.php',

            // Service
            'Services/AuthService.php' => 'app/Services/AuthService.php',

            // Requests
            'Requests/LoginRequest.php' => 'app/Http/Requests/Auth/LoginRequest.php',
            'Requests/RegisterRequest.php' => 'app/Http/Requests/Auth/RegisterRequest.php',
            'Requests/ForgotPasswordRequest.php' => 'app/Http/Requests/Auth/ForgotPasswordRequest.php',
            'Requests/ResetPasswordRequest.php' => 'app/Http/Requests/Auth/ResetPasswordRequest.php',

            // Routes
            'routes/auth.php' => 'routes/auth.php',
        ];

        $this->copyFiles($files, 'Authentication');
    }

    protected function copyUserManagementFiles()
    {
        $this->info('📝 Generating User Management files...');

        $files = [
            // Controller
            'Controllers/UserController.php' => 'app/Http/Controllers/UserController.php',

            // Service
            'Services/UserService.php' => 'app/Services/UserService.php',

            // Requests
            'Requests/StoreUserRequest.php' => 'app/Http/Requests/User/StoreUserRequest.php',
            'Requests/UpdateUserRequest.php' => 'app/Http/Requests/User/UpdateUserRequest.php',

            // Resources
            'Resources/UserResource.php' => 'app/Http/Resources/UserResource.php',
            'Resources/UserCollection.php' => 'app/Http/Resources/UserCollection.php',

            // Routes
            'routes/user.php' => 'routes/user.php',
        ];

        $this->copyFiles($files, 'User Management');
    }

    protected function copyRolesAndPermissionsFiles()
    {
        $this->info('📝 Generating Roles & Permissions files...');

        $files = [
            // Controllers
            'Controllers/RoleController.php' => 'app/Http/Controllers/RoleController.php',
            'Controllers/PermissionController.php' => 'app/Http/Controllers/PermissionController.php',

            // Services
            'Services/RoleService.php' => 'app/Services/RoleService.php',
            'Services/PermissionService.php' => 'app/Services/PermissionService.php',

            // Requests
            'Requests/StoreRoleRequest.php' => 'app/Http/Requests/Role/StoreRoleRequest.php',
            'Requests/UpdateRoleRequest.php' => 'app/Http/Requests/Role/UpdateRoleRequest.php',
            'Requests/StorePermissionRequest.php' => 'app/Http/Requests/Permission/StorePermissionRequest.php',
            'Requests/UpdatePermissionRequest.php' => 'app/Http/Requests/Permission/UpdatePermissionRequest.php',

            // Resources
            'Resources/RoleResource.php' => 'app/Http/Resources/RoleResource.php',
            'Resources/RoleCollection.php' => 'app/Http/Resources/RoleCollection.php',
            'Resources/PermissionResource.php' => 'app/Http/Resources/PermissionResource.php',
            'Resources/PermissionCollection.php' => 'app/Http/Resources/PermissionCollection.php',

            // Routes
            'routes/role.php' => 'routes/role.php',
            'routes/permission.php' => 'routes/permission.php',

            // Config
            'config/permission.php' => 'config/permission.php',
        ];

        $this->copyFiles($files, 'Roles & Permissions');
    }

    protected function copyFiles(array $files, string $component)
    {
        foreach ($files as $source => $destination) {
            $sourcePath = $this->packageStubPath . '/' . $source;
            $destinationPath = $this->basePath . '/' . $destination;

            // Check if source exists in package
            if (!File::exists($sourcePath)) {
                $this->warn("⚠️  Source file not found: {$source}");
                continue;
            }

            // Check if destination already exists
            if (File::exists($destinationPath) && !$this->option('force')) {
                if (!$this->confirm("File already exists: {$destination}. Do you want to replace it?", false)) {
                    $this->line("⏭️  Skipped: {$destination}");
                    continue;
                }
            }

            // Create directory if it doesn't exist
            $directory = dirname($destinationPath);
            if (!File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            // Copy the file
            File::copy($sourcePath, $destinationPath);
            $this->line("✅ Created: {$destination}");
        }
    }

    protected function installSpatiePackage()
    {
        $this->info('📦 Installing Spatie Laravel Permission package...');

        $composerJsonPath = base_path('composer.json');
        $composerJson = json_decode(File::get($composerJsonPath), true);

        // Check if package is already installed
        if (isset($composerJson['require']['spatie/laravel-permission'])) {
            $this->line('✅ Spatie Laravel Permission already installed');
            return;
        }

        $this->info('Running: composer require spatie/laravel-permission');
        $this->line('Please wait...');

        exec('composer require spatie/laravel-permission 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            $this->info('✅ Spatie Laravel Permission installed successfully');

            // Publish config and migrations
            $this->info('Publishing Spatie configuration and migrations...');
            Artisan::call('vendor:publish', [
                '--provider' => 'Spatie\Permission\PermissionServiceProvider'
            ]);
            $this->line('✅ Spatie files published');

        } else {
            $this->error('❌ Failed to install Spatie Laravel Permission');
            $this->warn('Please run manually: composer require spatie/laravel-permission');
        }
    }

    protected function displayNextSteps($includeRoles)
    {
        $this->info('📋 Next Steps:');
        $this->line('');
        $this->line('1. Add route files to your routes/api.php or web.php:');
        $this->line('   Route::middleware(\'api\')->group(base_path(\'routes/auth.php\'));');
        $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/user.php\'));');

        if ($includeRoles) {
            $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/role.php\'));');
            $this->line('   Route::middleware([\'api\', \'auth:sanctum\'])->group(base_path(\'routes/permission.php\'));');
            $this->line('');
            $this->line('2. Run migrations for roles and permissions:');
            $this->line('   php artisan migrate');
            $this->line('');
            $this->line('3. Update your User model to use HasRoles trait:');
            $this->line('   use Spatie\Permission\Traits\HasRoles;');
            $this->line('   class User extends Authenticatable {');
            $this->line('       use HasRoles;');
            $this->line('   }');
        } else {
            $this->line('');
            $this->line('2. Install Laravel Sanctum if not already installed:');
            $this->line('   composer require laravel/sanctum');
            $this->line('   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"');
            $this->line('   php artisan migrate');
        }

        $this->line('');
        $this->line('3. Update your .env file with mail configuration for password reset');
        $this->line('');
        $this->info('🎉 You\'re all set! Happy coding!');
    }
}