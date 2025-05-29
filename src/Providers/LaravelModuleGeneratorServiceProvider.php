<?php

namespace NahidFerdous\LaravelModuleGenerator\Providers;

use Illuminate\Support\ServiceProvider;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateDbDiagram;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromModelName;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GeneratePostmanCollection;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\ModuleRollback;

class LaravelModuleGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/module-generator.php', 'module-generator');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateModuleFromModelName::class,
                GenerateModuleFromYaml::class,
                GeneratePostmanCollection::class,
                GenerateDbDiagram::class,
                ModuleRollback::class,
            ]);

            // Publish stubs separately
            $this->publishes([
                __DIR__.'/../stubs' => base_path('module/stubs'),
            ], 'module-generator-stubs');

            // Publish config separately
            $this->publishes([
                __DIR__.'/../config/module-generator.php' => config_path('module-generator.php'),
            ], 'module-generator-config');

            // Publish both together with general tag
            $this->publishes([
                __DIR__.'/../stubs' => base_path('module/stubs'),
                __DIR__.'/../config/module-generator.php' => config_path('module-generator.php'),
            ], 'module-generator');

            // ✅ Auto-create models.yaml if not exists
            $this->ensureModelsYamlExists();
            $this->generateApiTraits();
            $this->generateMetaTrait();
        }
    }

    protected function generateApiTraits(): void
    {
        $directory = app_path('Traits');
        $filePath = $directory.'/ApiResponseTrait.php';

        // Ensure the Traits directory exists
        if (! file_exists($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        // Create the trait file if it doesn't exist
        if (! file_exists($filePath)) {
            $content = <<<PHP
<?php

namespace App\Traits;

trait ApiResponseTrait
{
    protected function success(\$message, \$data = null, \$status = 200): \\Illuminate\\Http\\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => \$data,
            'message' => \$message,
        ], \$status);
    }

    protected function failure(\$message, \$status = 400, \$errors = null): \\Illuminate\\Http\\JsonResponse
    {
        \$response = [
            'success' => false,
            'message' => \$message,
        ];
        if (\$errors) {
            if (is_array(\$errors)) {
                \$response['errors'] = \$errors;
            } elseif (is_string(\$errors)) {
                \$response['error'] = \$errors;
            } else {
                \$response['error'] = 'An unexpected error occurred.';
            }
        }

        return response()->json(\$response, \$status);
    }
}
PHP;

            file_put_contents($filePath, $content);
        }
    }

    protected function generateMetaTrait(): void
    {
        $directory = app_path('Traits');
        $filePath = $directory.'/MetaResponseTrait.php';

        // Ensure the Traits directory exists
        if (! file_exists($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        // Create the trait file if it doesn't exist
        if (! file_exists($filePath)) {
            $content = <<<PHP
<?php

namespace App\Traits;

use Illuminate\Pagination\LengthAwarePaginator;

trait MetaResponseTrait
{
    /**
     * Generate meta information for the resource collection.
     *
     * @return array<int|string, mixed>
     */
    protected function generateMeta(): array
    {
        if (\$this->resource instanceof LengthAwarePaginator) {
            return [
                'first_page_url' => \$this->url(1),
                'prev_page_url' => \$this->previousPageUrl(),
                'next_page_url' => \$this->nextPageUrl(),
                'last_page_url' => \$this->url(\$this->lastPage()),
                'path' => \$this->path(),
                'current_page' => \$this->currentPage(),
                'last_page' => \$this->lastPage(),
                'from' => \$this->firstItem(),
                'to' => \$this->lastItem(),
                'per_page' => \$this->perPage(),
                'total' => \$this->total(),
            ];
        }

        return [];
    }
}
PHP;

            file_put_contents($filePath, $content);
        }
    }

    protected function ensureModelsYamlExists(): void
    {
        $path = config('module-generator.models_path');
        $directory = dirname($path); // get the directory portion of the path

        if (! file_exists($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        if (! file_exists($path)) {
            file_put_contents($path, <<<'YAML'
# This file is auto-generated by LaravelModuleGenerator
# Define your models here. Example:

User:
  generate:
    model: false
    migration: false
    controller: false
    service: false
    request: false
    resource: false
    collection: false
  fields:
    name: string
    username: string:nullable
    email: string:unique
    email_verified_at: dateTime:nullable
    password: string

YAML
            );
        }
    }
}
