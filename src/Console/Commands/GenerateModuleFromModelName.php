<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateModuleFromModelName extends Command
{
    protected $signature = 'make:module {modelName}';
    protected $description = 'Generate model, controller, service, request, collection, and resource using custom stubs';

    public function handle(): int
    {
        $modelName    = Str::studly($this->argument('modelName'));
        $modelVar     = Str::camel($modelName);
        $pluralModel  = Str::pluralStudly($modelName);
        $tableName    = Str::snake(Str::plural($modelName));

        // Step 0: Migration
        $this->createMigration($tableName);

        // Step 1: Model
        $this->call('make:model', ['name' => $modelName]);

        // Step 2: Request
        $this->call('make:request', ['name' => "{$modelName}Request"]);
        $this->info("Request created: {$modelName}Request");

        // Step 3–5: Custom Stub-Based Files
        $this->generateFileFromStub(
            app_path("Services/{$modelName}Service.php"),
            'service',
            ['{{ model }}' => $modelName, '{{ variable }}' => $modelVar],
            "App\\Services\\{$modelName}Service"
        );

        $this->generateFileFromStub(
            app_path("Http/Controllers/v1/{$modelName}Controller.php"),
            'controller',
            [
                '{{ class }}'      => "{$modelName}Controller",
                '{{ model }}'      => $modelName,
                '{{ variable }}'   => $modelVar,
                '{{ modelPlural }}'=> $pluralModel,
                '{{ route }}'      => Str::snake($modelName),
            ],
            "App\\Http\\Controllers\\v1\\{$modelName}Controller"
        );

        $this->generateFileFromStub(
            app_path("Http/Resources/{$modelName}/{$modelName}Collection.php"),
            'collection',
            ['{{model}}' => $modelName, '{{modelVar}}' => $modelVar],
            "App\\Http\\Resources\\{$modelName}\\{$modelName}Collection"
        );

        // Step 6: Resource
        $resourcePath = app_path("Http/Resources/{$modelName}/{$modelName}Resource.php");
        if (! File::exists($resourcePath)) {
            $this->call('make:resource', ['name' => "{$modelName}/{$modelName}Resource"]);
            $this->info("Resource created: App\\Http\\Resources\\{$modelName}\\{$modelName}Resource");
        } else {
            $this->warn("Resource already exists: {$resourcePath}");
        }

        // Step 7: Append API Route
        $this->appendApiRoute($tableName, $modelName);

        return 0;
    }

    protected function createMigration(string $tableName): void
    {
        $migrationName = "create_{$tableName}_table";
        $this->call('make:migration', [
            'name'     => $migrationName,
            '--create' => $tableName,
        ]);
        $this->info("Migration created: {$migrationName}");
    }

    protected function generateFileFromStub(string $filePath, string $stubKey, array $replacements, string $displayName): void
    {
        if (File::exists($filePath)) {
            $this->warn("Already exists: {$displayName}");
            return;
        }

        File::ensureDirectoryExists(dirname($filePath));

        $stubPath = $this->resolveStubPath($stubKey);
        $content = str_replace(array_keys($replacements), array_values($replacements), File::get($stubPath));

        File::put($filePath, $content);
        $this->info("Created: {$displayName}");
    }

    protected function appendApiRoute(string $tableName, string $modelName): void
    {
        $controllerClass = "App\\Http\\Controllers\\v1\\{$modelName}Controller";
        $apiRoutesPath = base_path('routes/api.php');
        $routeDefinition = "Route::apiResource('{$tableName}', {$controllerClass}::class);";

        if (! Str::contains(File::get($apiRoutesPath), $routeDefinition)) {
            File::append($apiRoutesPath, "\n{$routeDefinition}\n");
            $this->info('API route added to routes/api.php');
        } else {
            $this->warn('API route already exists in routes/api.php');
        }
    }

    protected function resolveStubPath(string $stubKey): string
    {
        $config = config('module-generator');

        if (! $config || ! isset($config['stubs'][$stubKey])) {
            throw new \InvalidArgumentException("Stub not defined or missing for key: {$stubKey}");
        }

        $stubFile = $config['stubs'][$stubKey];
        $publishedPath = base_path("module/stub/{$stubFile}");
        $fallbackPath = __DIR__ . "/../../stubs/{$stubFile}";

        if (file_exists($publishedPath)) {
            return $publishedPath;
        }

        if (file_exists($fallbackPath)) {
            return $fallbackPath;
        }

        throw new \RuntimeException("Stub file not found at: {$publishedPath} or {$fallbackPath}");
    }
}
