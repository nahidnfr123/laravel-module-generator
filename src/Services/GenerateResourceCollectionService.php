<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateResourceCollectionService
{
    private GenerateModuleFromYaml $command;

    private array $generateConfig;

    private StubPathResolverService $pathResolverService;

    public function __construct(GenerateModuleFromYaml $command, array $generateConfig)
    {
        $this->command = $command;
        $this->generateConfig = $generateConfig;
        $this->pathResolverService = new StubPathResolverService;
    }

    /**
     * Handle collection file generation
     */
    public function handleCollectionGeneration(array $modelConfig, bool $force): void
    {
        if ($this->generateConfig['collection']) {
            $collectionPath = app_path("Http/Resources/{$modelConfig['studlyName']}/{$modelConfig['classes']['collection']}.php");

            if (File::exists($collectionPath) && ! $force) {
                $this->command->warn("⚠️ Collection already exists: {$modelConfig['classes']['collection']}");

                return;
            }
            if (File::exists($collectionPath)) {
                File::delete($collectionPath);
                $this->command->warn("⚠️ Deleted existing collection: {$modelConfig['classes']['collection']}");
            }

            $this->generateCollection($modelConfig['studlyName'], $modelConfig['classes']['collection'], $modelConfig['camelName']);
        }
    }

    /**
     * Generate resource collection class
     */
    protected function generateCollection(string $modelName, string $collectionClass, string $modelVar): void
    {
        $dir = app_path("Http/Resources/{$modelName}");
        $path = "{$dir}/{$collectionClass}.php";
        $stubPath = $this->pathResolverService->resolveStubPath('collection');

        File::ensureDirectoryExists($dir);
        File::put($path, str_replace(
            ['{{model}}', '{{modelVar}}'],
            [$modelName, $modelVar],
            File::get($stubPath)
        ));

        $this->command->info('🤫 Collection created.');
    }

    /**
     * Handle resource file generation
     */
    public function handleResourceGeneration(array $modelConfig, bool $force): void
    {
        if ($this->generateConfig['resource']) {
            $resourcePath = app_path("Http/Resources/{$modelConfig['studlyName']}/{$modelConfig['classes']['resource']}.php");

            if (File::exists($resourcePath) && ! $force) {
                $this->command->warn("⚠️ Resource already exists: {$modelConfig['classes']['resource']}");

                return;
            }

            if (File::exists($resourcePath)) {
                File::delete($resourcePath);
                $this->command->warn("⚠️ Deleted existing resource: {$modelConfig['classes']['resource']}");
            }

            $fields = $modelConfig['fields'] ?? [];
            $relations = $modelConfig['relations'] ?? [];

            $toArrayLines = [
                "'id' => \$this->id,", // Add ID by default
            ];

            foreach ($fields as $field => $definition) {
                $toArrayLines[] = "'$field' => \$this->$field,";
            }

            foreach ($relations as $relation => $relConfig) {
                $toArrayLines[] = "'$relation' => \$this->whenLoaded('$relation'),";
            }

            $toArrayBody = implode("\n            ", $toArrayLines);

            $namespace = "App\\Http\\Resources\\{$modelConfig['studlyName']}";
            $className = $modelConfig['classes']['resource'];

            $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Http\Resources\Json\JsonResource;

class {$className} extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(\$request): array
    {
        return [
            {$toArrayBody}
        ];
    }
}
PHP;

            File::put($resourcePath, $content);
            $this->command->info("✅ Resource created: {$modelConfig['classes']['resource']}");
        }
    }
}
