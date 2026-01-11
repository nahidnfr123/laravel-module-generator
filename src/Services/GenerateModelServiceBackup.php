<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateModelServiceBackup
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
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $migrationPath = $this->getMigrationPath($tableName);

        if (in_array('migration', $generateConfig, true) && ! ($migrationPath && File::exists($migrationPath))) {
            $modelName = Str::studly($modelName);
            Artisan::call('make:model', ['name' => $modelName, '--migration' => true]);
        } else {
            Artisan::call('make:model', ['name' => $modelName]);
        }

        $modelPath = app_path("Models/{$modelName}.php");
        if (! File::exists($modelPath)) {
            $this->command->warn("⚠️ Model file not found for: {$modelName}");

            return;
        }

        $fillableArray = $this->buildFillableArray($fields);
        $getters = $this->buildGetter($fields);
        $setters = $this->buildSetter($fields);
        $casts = $this->buildCasts($fields);
        $relationshipMethods = $this->buildRelationshipMethods($relations);

        $this->replaceModelWithStub($modelPath, $modelName, $fillableArray, $relationshipMethods,
            $casts,
            $getters,
            $setters
        );

        $this->command->info("🤫 Fillable fields and relationships added to {$modelName} model");
    }

    public function buildGetter(array $fields): string
    {
        $getters = '';

        foreach ($fields as $fieldName => $definition) {
            // Extract field type (before :)
            $type = explode(':', $definition)[0];

            if (! in_array($type, ['image', 'file'])) {
                continue;
            }

            $methodName = 'get'.Str::studly($fieldName).'Attribute';

            $getters .= <<<PHP


    public function {$methodName}(\$value): ?string
    {
        return getFileUrl(\$value);
    }
PHP;
        }

        return $getters;
    }

    public function buildSetter($fields): string
    {
        return '';
    }

    public function buildCasts(array $fields): string
    {
        $casts = [];

        foreach ($fields as $fieldName => $definition) {
            $type = explode(':', $definition)[0];

            $cast = match ($type) {
                'json' => 'array',
                'boolean' => 'boolean',
                'integer' => 'integer',
                'float',
                'double',
                'decimal' => 'float',
                'date' => 'date',
                'datetime',
                'timestamp' => 'datetime',
                default => null,
            };

            if ($cast) {
                $casts[] = "'{$fieldName}' => '{$cast}'";
            }
        }

        if (empty($casts)) {
            return '';
        }

        return "\n        ".implode(",\n        ", $casts)."\n    ";
    }

    private function getMigrationPath(string $tableName): ?string
    {
        $pattern = database_path(
            "migrations/*_create_{$tableName}_table.php"
        );

        $files = glob($pattern);

        if (empty($files)) {
            return null;
        }

        sort($files);

        return end($files);
    }

    /**
     * Build fillable array string for model
     */
    private function buildFillableArray(array $fields): string
    {
        $fillableFields = array_map(fn ($field) => "'$field'", array_keys($fields));

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
    private function replaceModelWithStub(
        string $modelPath,
        string $modelName,
        string $fillableArray,
        string $relationshipMethods,
        string $casts,
        string $getters,
        string $setters
    ): void {
        try {
            $stubPath = $this->stubPathResolver->resolveStubPath('model');
            $stubContent = File::get($stubPath);

            $modelContent = str_replace([
                '{{ model }}',
                '{{ fillable }}',
                '{{ relations }}',
                '{{ casts }}',
                '{{ getter }}',
                '{{ setter }}',
            ], [
                $modelName,
                $fillableArray,
                $relationshipMethods,
                $casts,
                $getters,
                $setters,
            ], $stubContent);

            File::put($modelPath, $modelContent);
        } catch (\Exception $e) {
            $this->command->error('Failed to generate model using stub: '.$e->getMessage());

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
            '/(class\s+'.$modelName.'\s+extends\s+Model\s*\{)/',
            "$1\n\n    {$fillableArray}\n{$relationshipMethods}\n",
            $modelContent
        );

        File::put($modelPath, $modelContent);
    }
}
