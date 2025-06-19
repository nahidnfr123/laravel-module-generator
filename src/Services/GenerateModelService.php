<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateModelService
{
    private GenerateModuleFromYaml $command;

    private StubPathResolverService $stubPathResolver;

    public function __construct(GenerateModuleFromYaml $command)
    {
        $this->command = $command;
        $this->stubPathResolver = new StubPathResolverService;
    }

    /**
     * Generate model file with fillable fields and relationships
     */
    public function generateModel(string $modelName, array $fields, array $relations = [], $generateConfig = []): void
    {
        if ($generateConfig['migration'] === true) {
            $modelName = Str::studly($modelName);
            Artisan::call('make:model', ['name' => $modelName, '--migration' => true]);
        } else {
            Artisan::call('make:model', ['name' => $modelName]);
        }

        $modelPath = app_path("Models/{$modelName}.php");
        if (!File::exists($modelPath)) {
            $this->command->warn("⚠️ Model file not found for: {$modelName}");

            return;
        }

        $fillableArray = $this->buildFillableArray($fields);
        $relationshipMethods = $this->buildRelationshipMethods($relations);

        $this->replaceModelWithStub($modelPath, $modelName, $fillableArray, $relationshipMethods);

        $this->command->info("🤫 Fillable fields and relationships added to {$modelName} model");
    }

    /**
     * Build fillable array string for model
     */
    private function buildFillableArray(array $fields): string
    {
        $fillableFields = array_map(fn($field) => "'$field'", array_keys($fields));

        return implode(",\n        ", $fillableFields);
    }

    /**
     * Build relationship methods for model
     */
    private function buildRelationshipMethods(array $relations): string
    {
        if (empty($relations)) {
            return '';
        }

        $relationshipMethods = '';
        foreach ($relations as $relationName => $meta) {
            $relationName = Str::camel($relationName);
            $type = $meta['type'];
            $relatedModel = $meta['model'];

            $relationshipMethods .= <<<PHP


    public function {$relationName}()
    {
        return \$this->{$type}({$relatedModel}::class);
    }
PHP;
        }

        return $relationshipMethods;
    }

    /**
     * Replace model content using stub template
     */
    private function replaceModelWithStub(string $modelPath, string $modelName, string $fillableArray, string $relationshipMethods): void
    {
        try {
            $stubPath = $this->stubPathResolver->resolveStubPath('model');
            $stubContent = File::get($stubPath);

            $modelContent = str_replace([
                '{{ model }}',
                '{{ fillable }}',
                '{{ relations }}',
            ], [
                $modelName,
                $fillableArray,
                $relationshipMethods,
            ], $stubContent);

            File::put($modelPath, $modelContent);
        } catch (\Exception $e) {
            $this->command->error('Failed to generate model using stub: ' . $e->getMessage());

            // Fallback to the original method if stub fails
            $this->insertModelContentFallback($modelPath, $modelName, $fillableArray, $relationshipMethods);
        }
    }

    /**
     * Fallback method to insert content into existing model (original implementation)
     */
    private function insertModelContentFallback(string $modelPath, string $modelName, string $fillableFields, string $relationshipMethods): void
    {
        $modelContent = File::get($modelPath);

        $fillableArray = "protected \$fillable = [\n        {$fillableFields},\n    ];";

        $modelContent = preg_replace(
            '/(class\s+' . $modelName . '\s+extends\s+Model\s*\{)/',
            "$1\n\n    {$fillableArray}\n{$relationshipMethods}\n",
            $modelContent
        );

        File::put($modelPath, $modelContent);
    }
}
