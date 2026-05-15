<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use NahidFerdous\LaravelModuleGenerator\Concerns\HandlesFileGeneration;
use NahidFerdous\LaravelModuleGenerator\Contracts\OutputInterface;

class GenerateResourceCollectionService
{
    use HandlesFileGeneration;

    private OutputInterface $command;

    private array $generateConfig;

    private StubPathResolverService $pathResolverService;

    public function __construct(OutputInterface $command, array $generateConfig)
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
        if (in_array('collection', $this->generateConfig, true)) {
            $collectionPath = app_path("Http/Resources/{$modelConfig['studlyName']}/{$modelConfig['classes']['collection']}.php");
            if ($this->guardAgainstExisting($collectionPath, "Collection {$modelConfig['classes']['collection']}", $force)) {
                $this->generateCollection($modelConfig['studlyName'], $modelConfig['classes']['collection'], $modelConfig['camelName']);
            }
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
        if (in_array('resource', $this->generateConfig, true)) {
            $resourcePath = app_path("Http/Resources/{$modelConfig['studlyName']}/{$modelConfig['classes']['resource']}.php");
            if ($this->guardAgainstExisting($resourcePath, "Resource {$modelConfig['classes']['resource']}", $force)) {
                $this->generateResource($modelConfig);
            }
        }
    }

    /**
     * Generate resource class from stub
     */
    protected function generateResource(array $modelConfig): void
    {
        $dir = app_path("Http/Resources/{$modelConfig['studlyName']}");
        $path = "{$dir}/{$modelConfig['classes']['resource']}.php";
        $stubPath = $this->pathResolverService->resolveStubPath('resource');

        File::ensureDirectoryExists($dir);

        $fields = $modelConfig['fields'] ?? [];
        $relations = $modelConfig['relations'] ?? [];

        $toArrayLines = [
            "'id' => \$this->id,",
        ];

        foreach ($fields as $field => $definition) {
            $toArrayLines[] = "'$field' => \$this->$field,";
        }

        foreach ($relations as $relation => $relConfig) {
            $toArrayLines[] = "'$relation' => \$this->whenLoaded('$relation'),";
        }

        $toArrayBody = implode("\n            ", $toArrayLines);

        $stubContent = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ fields }}'],
            [
                app()->getNamespace() . "Http\\Resources\\{$modelConfig['studlyName']}",
                $modelConfig['classes']['resource'],
                $toArrayBody,
            ],
            File::get($stubPath)
        );

        File::put($path, $stubContent);
        $this->command->info("✅ Resource created: {$modelConfig['classes']['resource']}");
    }
}
