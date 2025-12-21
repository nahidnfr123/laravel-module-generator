<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\Services;

class SocialAuthenticationService extends BaseAuthModuleService
{
    public function generate(): void
    {
        $this->command->info('📝 Generating Social Authentication files...');

        $this->copySocialAuthFiles();
        $this->createMigration();
        $this->addSocialAccountsRelationship();
    }

    protected function copySocialAuthFiles(): void
    {
        $files = [
            'Controllers/SocialAuthController' => 'app/Http/Controllers/SocialAuthController.php',
            'Services/SocialAuthService' => 'app/Services/SocialAuthService.php',
            'routes/social-auth' => 'routes/api/social-auth.php',
            'Models/SocialAccount' => 'app/Models/SocialAccount.php',
            //'migrations/create_social_accounts_table' => $this->getMigrationPath(),
        ];

        $this->copyFiles($files);
    }

    protected function getMigrationPath(): string
    {
        $timestamp = date('Y_m_d_His');
        return "database/migrations/{$timestamp}_create_social_accounts_table.php";
    }

    protected function createMigration(): void
    {
        $this->command->info('📝 Creating social_accounts migration...');

        $migrationPath = database_path('migrations');
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_create_social_accounts_table.php";
        $destination = "{$migrationPath}/{$filename}";

        if (!file_exists($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $stubPath = $this->getStubPath('migrations/create_social_accounts_table');

        if (file_exists($stubPath . '.php')) {
            copy($stubPath . '.php', $destination);
            $this->command->line("✅ Created: {$destination}");
        }
    }

    protected function getStubPath(string $filename): string
    {
        return __DIR__ . "/../../_stubs/AuthModule/migrations/{$filename}.stub";
    }

    protected function addSocialAccountsRelationship(): void
    {
        $this->command->info('📝 Checking User model for social accounts relationship...');

        $userModelPath = app_path('Models/User.php');

        if (!file_exists($userModelPath)) {
            $this->command->warn('⚠️  User model not found. Please add the socialAccounts relationship manually.');
            return;
        }

        $content = file_get_contents($userModelPath);

        // Check if relationship already exists
        if (strpos($content, 'function socialAccounts') !== false) {
            $this->command->line('ℹ️  Social accounts relationship already exists in User model');
            return;
        }

        // Add the relationship method before the last closing brace
        $relationship = "\n    /**\n     * Get the social accounts for the user.\n     */\n    public function socialAccounts()\n    {\n        return \$this->hasMany(SocialAccount::class);\n    }\n";

        $content = preg_replace('/}\s*$/', $relationship . "}\n", $content);

        file_put_contents($userModelPath, $content);
        $this->command->line('✅ Added socialAccounts relationship to User model');
    }
}