<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\Services\AccessControlService;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\Services\AuthenticationService;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\Services\EmailVerificationService;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\Services\SocialAuthenticationService;
use Symfony\Component\Console\Exception\CommandNotFoundException;

class GenerateAuthModule extends Command
{
    protected $signature = 'auth:generate
                            {--force : Overwrite existing files without confirmation}
                            {--skip-roles : Skip roles and permissions setup}
                            {--skip-email-verification : Skip email verification setup}
                            {--with-social-login : Include social authentication setup}';

    protected $description = 'Generate authentication, user management, and optionally roles & permissions';

    /**
     * @throws \Exception
     */
    public function handle()
    {
        $this->info('🚀 Starting Authentication & User Management Generation...');
        $this->newLine();

        if (!$this->checkDatabaseConnection()) {
            return self::FAILURE;
        }

        $this->newLine();

        $apiAuthDriver = $this->choice(
            'Which API authentication do you want to use?',
            ['Sanctum', 'Passport'],
            0
        );
        // Normalize value
        $apiAuthDriver = strtolower($apiAuthDriver);

        $this->info("🔐 Using {$apiAuthDriver} for API authentication.");

        $this->newLine();

        // Ask about roles and permissions
        $includeRoles = !$this->option('skip-roles') &&
            $this->confirm('Do you want to add roles and permissions management?', true);

        // Ask about email verification
        $includeEmailVerification = !$this->option('skip-email-verification') &&
            $this->confirm('Do you want to enable email verification?', true);

        // Ask about social authentication
        $includeSocialAuth = $this->option('with-social-login') ||
            $this->confirm('Do you want to add social authentication?', true);

        if (!$this->runRequiredCommand('install:api')) {
            return self::FAILURE;
        }

        // Generate Authentication & User Management
        $authService = new AuthenticationService($this);
        $authService->generate($apiAuthDriver);

        // Generate Email Verification if requested
        if ($includeEmailVerification) {
            $emailVerificationService = new EmailVerificationService($this);
            $emailVerificationService->generate();
        }

        // Generate Social Authentication if requested
        if ($includeSocialAuth) {
            if (!$this->installSocialite()) {
                $this->warn('⚠️  Failed to install Laravel Socialite. You may need to install it manually.');
            }

            $socialAuthService = new SocialAuthenticationService($this);
            $socialAuthService->generate();
        }

        // Generate Access Control (Roles & Permissions) if requested
        if ($includeRoles) {
            $accessControlService = new AccessControlService($this);
            $accessControlService->generate();
        }

        $this->newLine();
        $this->info('✅ Authentication system generated successfully!');
        $this->newLine();

        $this->displayNextSteps($includeRoles, $includeEmailVerification, $includeSocialAuth);

        return Command::SUCCESS;
    }

    /**
     * Check if the database connection is working
     */
    protected function checkDatabaseConnection(): bool
    {
        $this->info('🔍 Checking database connection...');

        try {
            \DB::connection()->getPdo();
            $this->line('✅ Database connected successfully');
            return true;
        } catch (\Exception $e) {
            $this->error('❌ Database connection failed: ' . $e->getMessage());
            $this->warn('Please configure your database in .env file and ensure the database server is running');
            return false;
        }
    }

    /**
     * Install Laravel Socialite package
     */
    protected function installSocialite(): bool
    {
        $this->info('📦 Installing Laravel Socialite...');

        try {
            $process = proc_open(
                'composer require laravel/socialite',
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                base_path()
            );

            if (is_resource($process)) {
                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $returnCode = proc_close($process);

                if ($returnCode === 0) {
                    $this->line('✅ Laravel Socialite installed successfully');
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->error('Failed to install Socialite: ' . $e->getMessage());
            return false;
        }
    }

    protected function displayNextSteps(bool $includeRoles, bool $includeEmailVerification, bool $includeSocialAuth): void
    {
        $this->info('📋 Next Steps:');
        $this->line('');
        $this->line('1. Add route files to your routes/api.php or web.php:');
        $this->line('   Route::middleware(\'api\')->group(base_path(\'routes/api/auth.php\'));');
        $this->line('   Route::middleware([\'api\', \'auth:api\'])->group(base_path(\'routes/user.php\'));');

        if ($includeRoles) {
            $this->line('   Route::middleware([\'api\', \'auth:api\'])->group(base_path(\'routes/api/access-control.php\'));');
        }

        if ($includeSocialAuth) {
            $this->line('   Route::middleware(\'api\')->group(base_path(\'routes/api/social-auth.php\'));');
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

        if ($includeSocialAuth) {
            $this->line('');
            $nextStep = (int)$step + 1;
            $this->line($nextStep . '. Configure social authentication providers in config/services.php:');
            $this->line('');
            $this->line('   \'google\' => [');
            $this->line('       \'client_id\' => env(\'GOOGLE_CLIENT_ID\'),');
            $this->line('       \'client_secret\' => env(\'GOOGLE_CLIENT_SECRET\'),');
            $this->line('       \'redirect\' => env(\'GOOGLE_REDIRECT_URI\', \'/auth/google/callback\'),');
            $this->line('   ],');
            $this->line('   \'facebook\' => [');
            $this->line('       \'client_id\' => env(\'FACEBOOK_CLIENT_ID\'),');
            $this->line('       \'client_secret\' => env(\'FACEBOOK_CLIENT_SECRET\'),');
            $this->line('       \'redirect\' => env(\'FACEBOOK_REDIRECT_URI\', \'/auth/facebook/callback\'),');
            $this->line('   ],');
            $this->line('   \'github\' => [');
            $this->line('       \'client_id\' => env(\'GITHUB_CLIENT_ID\'),');
            $this->line('       \'client_secret\' => env(\'GITHUB_CLIENT_SECRET\'),');
            $this->line('       \'redirect\' => env(\'GITHUB_REDIRECT_URI\', \'/auth/github/callback\'),');
            $this->line('   ],');
            $this->line('');
            $this->line('   And add the credentials to your .env file');
        }

        if ($includeEmailVerification) {
            $this->line('');
            $this->line('→ Email verification has been enabled in your User model');
            $this->line('  Users will need to verify their email before accessing protected routes');
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