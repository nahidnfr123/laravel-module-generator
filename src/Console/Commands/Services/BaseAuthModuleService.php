<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

use function app_path;
use function base_path;

abstract class BaseAuthModuleService
{
    protected Command $command;

    protected string $basePath;

    public string $apiDriver;

    protected string $packageStubPath;

    public function __construct(Command $command)
    {
        $this->command = $command;
        $this->basePath = base_path();
        $this->packageStubPath = __DIR__.'/../../../_stubs/AuthModule';
    }

    protected function run(string $command, array $arguments = []): void
    {
        $this->command->line("▶ Running: {$command}");

        if (str_starts_with($command, 'composer')) {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(null);
            $process->run(function ($type, $buffer) {
                $this->command->line($buffer);
            });

            if (! $process->isSuccessful()) {
                throw new \RuntimeException("Command failed: {$command}");
            }

            return;
        }

        // Artisan commands
        Artisan::call(
            str_replace('php artisan ', '', $command),
            $arguments,
            $this->command->getOutput()
        );
    }

    /**
     * Run shell command directly (for commands that need fresh app bootstrap)
     * Use this when you need Laravel to reload service providers
     */
    protected function runShell(string $command): void
    {
        $this->command->line("▶ Running: {$command}");

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(null);
        $process->run(function ($type, $buffer) {
            $this->command->line($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Command failed: {$command}");
        }
    }

    /**
     * Copy files from stub to destination
     */
    protected function copyFiles(array $files): void
    {
        foreach ($files as $source => $destination) {
            $sourcePath = $this->packageStubPath.'/'.$source.'.stub';
            $destinationPath = $this->basePath.'/'.$destination;

            if (! File::exists($sourcePath)) {
                $this->command->warn("⚠️  Source file not found: {$sourcePath}");

                continue;
            }

            if (File::exists($destinationPath) && ! $this->command->option('force')) {
                if (! $this->command->confirm("File already exists: {$destination}. Do you want to replace it?", false)) {
                    $this->command->line("⏭️  Skipped: {$destination}");

                    continue;
                }
            }

            $directory = dirname($destinationPath);
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::copy($sourcePath, $destinationPath);
            $this->command->line("✅ Created: {$destination}");
        }
    }

    /**
     * Update User model with traits and interfaces
     */
    protected function updateUserModel(array $updates): void
    {
        $userModelPath = app_path('Models/User.php');

        if (! File::exists($userModelPath)) {
            $this->command->warn('⚠️  User model not found at: '.$userModelPath);

            return;
        }

        $this->command->info('📝 Updating User model...');

        $content = File::get($userModelPath);
        $modified = false;

        foreach ($updates as $update) {
            $result = $this->applyUserModelUpdate($content, $update);
            $content = $result['content'];
            if ($result['modified']) {
                $modified = true;
                $this->command->line($result['message']);
            }
        }

        if ($modified) {
            File::put($userModelPath, $content);
            $this->command->info('✅ User model updated successfully');
        } else {
            $this->command->line('ℹ️  User model already up to date');
        }
    }

    /**
     * Apply a single update to the User model
     */
    protected function applyUserModelUpdate(string $content, array $update): array
    {
        $modified = false;
        $message = '';

        switch ($update['type']) {
            case 'interface':
                if (! str_contains($content, $update['interface'])) {
                    $content = str_replace(
                        $update['search'],
                        $update['replace'],
                        $content
                    );

                    $content = preg_replace(
                        '/class User extends Authenticatable/',
                        $update['class_declaration'],
                        $content
                    );

                    $modified = true;
                    $message = "✅ Added {$update['interface']} interface";
                }
                break;

            case 'trait':
                if (! str_contains($content, $update['use_statement'])) {
                    $content = str_replace(
                        'use Illuminate\Notifications\Notifiable;',
                        "use Illuminate\Notifications\Notifiable;\n{$update['use_statement']}",
                        $content
                    );

                    if (preg_match('/class User.*?\{.*?use ([^;]+);/s', $content, $matches)) {
                        $traits = $matches[1];
                        if (! str_contains($traits, $update['trait'])) {
                            $newTraits = trim($traits).', '.$update['trait'];
                            $content = str_replace(
                                "use {$traits};",
                                "use {$newTraits};",
                                $content
                            );
                        }
                    }

                    $modified = true;
                    $message = "✅ Added {$update['trait']} trait";
                }
                break;
        }

        return [
            'content' => $content,
            'modified' => $modified,
            'message' => $message,
        ];
    }

    /**
     * Update bootstrap/app.php with middleware
     */
    public function updateBootstrapApp(string $apiAuthDriver, array $middlewareAliases = []): void
    {
        $bootstrapPath = base_path('bootstrap/app.php');

        if (! File::exists($bootstrapPath)) {
            $this->command->warn('⚠️  bootstrap/app.php not found');

            return;
        }

        $this->command->info('📝 Updating bootstrap/app.php...');

        $content = File::get($bootstrapPath);
        $modified = false;

        if (preg_match('/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{(.*?)\}\)/s', $content, $matches)) {
            $middlewareContent = $matches[1];
            $updatedMiddlewareContent = $middlewareContent;

            if (($apiAuthDriver === 'sanctum') && ! str_contains($middlewareContent, '$middleware->statefulApi()')) {
                $updatedMiddlewareContent = "\n        \$middleware->statefulApi();";
                $modified = true;
                $this->command->line('✅ Added statefulApi middleware');
            }

            if (! empty($middlewareAliases)) {
                $result = $this->addMiddlewareAliases($updatedMiddlewareContent, $middlewareAliases);
                $updatedMiddlewareContent = $result['content'];
                $modified = $modified || $result['modified'];
            }

            $content = preg_replace(
                '/->withMiddleware\(function\s*\(Middleware\s+\$middleware\)\s*:\s*void\s*\{.*?\}\)/s',
                "->withMiddleware(function (Middleware \$middleware): void {{$updatedMiddlewareContent}\n    })",
                $content
            );
        }

        if ($modified) {
            File::put($bootstrapPath, $content);
            $this->command->info('✅ bootstrap/app.php updated successfully');
        } else {
            $this->command->line('ℹ️  bootstrap/app.php already up to date');
        }
    }

    /**
     * Add middleware aliases to bootstrap/app.php
     */
    protected function addMiddlewareAliases(string $content, array $aliases): array
    {
        $modified = false;

        if (! str_contains($content, '$middleware->alias(')) {
            $aliasesStr = "\n        \$middleware->alias([\n";
            foreach ($aliases as $key => $class) {
                $aliasesStr .= "            '{$key}' => {$class},\n";
            }
            $aliasesStr .= '        ]);';
            $content .= $aliasesStr;
            $modified = true;
            $this->command->line('✅ Added middleware aliases');
        } else {
            foreach ($aliases as $key => $class) {
                if (! str_contains($content, "'{$key}'")) {
                    $content = preg_replace(
                        '/(\$middleware->alias\(\[[^\]]*)/s',
                        "$1\n            '{$key}' => {$class},",
                        $content
                    );
                    $modified = true;
                }
            }
        }

        return ['content' => $content, 'modified' => $modified];
    }
}
