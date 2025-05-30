<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateModelService
{
    private GenerateModuleFromYaml $command;

    public function __construct(GenerateModuleFromYaml $command)
    {
        $this->command = $command;
    }

    /**
     * Generate model file with fillable fields and relationships
     */
    public function generateModel(string $modelName, array $fields, array $relations = []): void
    {
        Artisan::call('make:model', ['name' => $modelName, '--migration' => true]);

        $modelPath = app_path("Models/{$modelName}.php");
        if (!File::exists($modelPath)) {
            $this->command->warn("⚠️ Model file not found for: {$modelName}");

            return;
        }

        $fillableArray = $this->buildFillableArray($fields);
        $relationshipMethods = $this->buildRelationshipMethods($relations);

        $this->insertModelContent($modelPath, $modelName, $fillableArray, $relationshipMethods);

        $this->command->info("🤫 Fillable fields and relationships added to {$modelName} model");
    }

    /**
     * Build fillable array string for model
     */
    private function buildFillableArray(array $fields): string
    {
        $fillableFields = array_map(fn($field) => "        '{$field}'", array_keys($fields));

        return "protected \$fillable = [\n" . implode(",\n", $fillableFields) . ",\n    ];";
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
     * Insert fillable and relationship content into model file
     */
    private function insertModelContent(string $modelPath, string $modelName, string $fillableArray, string $relationshipMethods): void
    {
        $modelContent = File::get($modelPath);

        $modelContent = preg_replace(
            '/(class\s+' . $modelName . '\s+extends\s+Model\s*\{)/',
            "$1\n\n    {$fillableArray}\n{$relationshipMethods}\n",
            $modelContent
        );

        File::put($modelPath, $modelContent);
    }
}
