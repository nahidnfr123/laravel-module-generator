<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use function NahidFerdous\LaravelModuleGenerator\Services\service\base_path;
use function NahidFerdous\LaravelModuleGenerator\Services\service\database_path;

class AccessControlService extends BaseAuthModuleService
{
    public function generate(): void
    {
        $this->installAndConfigureSpatiePackage();
        $this->copyRolesAndPermissionsFiles();
        $this->copyPermissionMigrationWithTimestamp();
        $this->updateUserModelForRoles();
        $this->updateBootstrapForRoles();
    }

    protected function copyRolesAndPermissionsFiles(): void
    {
        $this->command->info('📝 Generating Roles & Permissions files...');

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

        $this->copyFiles($files);
    }

    protected function copyPermissionMigrationWithTimestamp(): void
    {
        $this->command->info('📝 Copying custom permission migration...');

        $stubPath = $this->packageStubPath . '/migrations/2025_00_00_000000_add_type_to_permissions_table.stub';

        if (!File::exists($stubPath)) {
            $this->command->warn('⚠️  Migration stub not found: ' . $stubPath);
            return;
        }

        $migrationsPath = database_path('migrations');
        $spatieMigrations = File::glob($migrationsPath . '/*_create_permission_tables.php');

        $timestamp = null;

        if (!empty($spatieMigrations)) {
            $latestMigration = end($spatieMigrations);
            $filename = basename($latestMigration);

            preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $filename, $matches);

            if (isset($matches[1])) {
                $originalTimestamp = str_replace('_', '', $matches[1]);
                $dateTime = \DateTime::createFromFormat('YmdHis', $originalTimestamp);
                $dateTime->modify('+1 second');
                $timestamp = $dateTime->format('Y_m_d_His');

                $this->command->line("✅ Found Spatie migration timestamp: {$matches[1]}");
                $this->command->line("✅ Using timestamp: {$timestamp}");
            }
        }

        if (!$timestamp) {
            $timestamp = date('Y_m_d_His');
            $this->command->warn('⚠️  Spatie migration not found, using current timestamp');
        }

        $destinationFilename = "{$timestamp}_add_type_to_permissions_table.php";
        $destinationPath = $migrationsPath . '/' . $destinationFilename;

        $existingMigrations = File::glob($migrationsPath . '/*_add_type_to_permissions_table.php');

        if (!empty($existingMigrations) && !$this->command->option('force')) {
            $existingFile = basename($existingMigrations[0]);
            if (!$this->command->confirm("Migration already exists: {$existingFile}. Do you want to replace it?", false)) {
                $this->command->line("⏭️  Skipped: {$destinationFilename}");
                return;
            }
            File::delete($existingMigrations[0]);
        }

        File::copy($stubPath, $destinationPath);
        $this->command->line("✅ Created: database/migrations/{$destinationFilename}");
    }

    protected function installAndConfigureSpatiePackage(): void
    {
        $this->command->info('📦 Installing and configuring Spatie Laravel Permission package...');

        $composerJsonPath = base_path('composer.json');
        $composerJson = json_decode(File::get($composerJsonPath), true);

        $freshInstall = false;

        if (!isset($composerJson['require']['spatie/laravel-permission'])) {
            $this->command->info('Running: composer require spatie/laravel-permission');
            exec('composer require spatie/laravel-permission 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                $this->command->error('❌ Failed to install Spatie Laravel Permission');
                $this->command->warn('Please run manually: composer require spatie/laravel-permission');
                return;
            }

            $freshInstall = true;
            $this->command->info('✅ Spatie Laravel Permission installed');
        } else {
            $this->command->line('✅ Spatie Laravel Permission already installed');
        }

        if ($freshInstall) {
            $this->command->info('Refreshing Composer autoload...');
            exec('composer dump-autoload 2>&1');

            $this->command->info('Running package discovery...');
            Artisan::call('package:discover', [], $this->command->getOutput());
        }

        $this->command->info('Publishing Spatie config and migrations...');

        exec(
            'php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --force 2>&1',
            $output,
            $exitCode
        );

        if ($exitCode !== 0) {
            $this->command->error('❌ Failed to publish Spatie resources');
            $this->command->line(implode("\n", $output));
            return;
        }

        $this->command->info('✅ Spatie config and migrations published');

        $this->command->info('Clearing caches...');
        Artisan::call('optimize:clear', [], $this->command->getOutput());

        $this->command->info('✅ Spatie Permission fully configured');
    }

    protected function updateUserModelForRoles(): void
    {
        $updates = [
            [
                'type' => 'trait',
                'trait' => 'HasRoles',
                'use_statement' => 'use Spatie\Permission\Traits\HasRoles;',
            ]
        ];

        $this->updateUserModel($updates);
    }

    protected function updateBootstrapForRoles(): void
    {
        $middlewareAliases = [
            'auth' => '\App\Http\Middleware\Authenticate::class',
            'cors' => 'App\Http\Middleware\Cors::class',
            'role' => '\Spatie\Permission\Middleware\RoleMiddleware::class',
            'permission' => '\Spatie\Permission\Middleware\PermissionMiddleware::class',
            'role_or_permission' => '\Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class',
        ];

        $this->updateBootstrapApp($middlewareAliases);
    }
}