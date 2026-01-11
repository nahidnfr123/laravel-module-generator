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
     * Generate a model file with fillable fields and relationships
     */
    public function generateModel(string $modelName, array $fields, array $relations = [], $generateConfig = [], string $primaryKey = 'id', bool $softDeletes = false): void
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
            $setters,
            $primaryKey,
            $softDeletes,
            $generateConfig
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
     * Build a fillable array string for a model
     */
    private function buildFillableArray(array $fields): string
    {
        $fillableFields = array_map(fn ($field) => "'$field'", array_keys($fields));

        return implode(",\n        ", $fillableFields);
    }

    /**
     * Build relationship methods for a model
     */
    private function buildRelationshipMethods(array $relations): string
    {
        if (empty($relations)) {
            return '';
        }

        $relationshipMethods = '';

        foreach ($relations as $relationType => $relatedModels) {
            $relationType = Str::camel($relationType);

            // Parse the related models (can be comma-separated)
            $models = is_array($relatedModels) ? $relatedModels : array_map('trim', explode(',', $relatedModels));

            foreach ($models as $modelDefinition) {
                $modelDefinition = trim($modelDefinition);

                // Parse model name and optional method name
                // Format: ModelName:methodName or just ModelName
                $parts = explode(':', $modelDefinition);
                $relatedModel = trim($parts[0]);
                $methodName = isset($parts[1]) ? trim($parts[1]) : null;

                // Generate a method name if not provided
                if (! $methodName) {
                    $methodName = $this->generateMethodName($relationType, $relatedModel);
                }

                $relationshipMethods .= $this->buildRelationshipMethod($relationType, $relatedModel, $methodName);
            }
        }

        return $relationshipMethods;
    }

    private function generateMethodName(string $relationType, string $relatedModel): string
    {
        return match ($relationType) {
            'hasOne', 'belongsTo', 'morphOne', 'morphTo' => Str::camel($relatedModel),
            'hasMany', 'belongsToMany', 'hasManyThrough', 'morphMany', 'morphToMany', 'morphedByMany' => Str::camel(Str::plural($relatedModel)),
            default => Str::camel($relatedModel),
        };
    }

    private function buildRelationshipMethod(string $relationType, string $relatedModel, string $methodName): string
    {
        $modelClass = $relatedModel;

        switch ($relationType) {
            case 'hasOne':
                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return \$this->hasOne({$modelClass}::class);
    }
PHP;

            case 'hasMany':
                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return \$this->hasMany({$modelClass}::class);
    }
PHP;

            case 'belongsTo':
                $foreignKey = Str::snake($relatedModel).'_id';

                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return \$this->belongsTo({$modelClass}::class);
    }
PHP;

            case 'belongsToMany':
                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return \$this->belongsToMany({$modelClass}::class);
    }
PHP;

            case 'hasOneThrough':
                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\HasOneThrough
    {
        return \$this->hasOneThrough({$modelClass}::class, Through::class);
    }
PHP;

            case 'hasManyThrough':
                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return \$this->hasManyThrough({$modelClass}::class, Through::class);
    }
PHP;

            case 'morphOne':
                $morphName = Str::snake($methodName);

                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return \$this->morphOne({$modelClass}::class, '{$morphName}');
    }
PHP;

            case 'morphMany':
                $morphName = Str::singular(Str::snake($methodName));

                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return \$this->morphMany({$modelClass}::class, '{$morphName}');
    }
PHP;

            case 'morphTo':
                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return \$this->morphTo();
    }
PHP;

            case 'morphToMany':
                $morphName = Str::snake($methodName);

                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return \$this->morphToMany({$modelClass}::class, '{$morphName}');
    }
PHP;

            case 'morphedByMany':
                $morphName = Str::snake($methodName);

                return <<<PHP


    public function {$methodName}(): \Illuminate\Database\Eloquent\Relations\MorphedByMany
    {
        return \$this->morphedByMany({$modelClass}::class, '{$morphName}');
    }
PHP;

            default:
                // Fallback for custom or unknown relationship types
                return <<<PHP


    public function {$methodName}()
    {
        return \$this->{$relationType}({$modelClass}::class);
    }
PHP;
        }
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
        string $setters,
        string $primaryKey = 'id',
        bool $softDeletes = false,
        $generateConfig = []
    ): void {
        try {
            $stubPath = $this->stubPathResolver->resolveStubPath('model');
            $stubContent = File::get($stubPath);

            // Build use statements
            $useStatements = [];

            if ($softDeletes) {
                $useStatements[] = "use Illuminate\Database\Eloquent\SoftDeletes;";
            }

            if (in_array('seeder', $generateConfig, true)) {
                $useStatements[] = "use Illuminate\Database\Eloquent\Factories\HasFactory;";
            }

            $useStatementsString = ! empty($useStatements) ? implode("\n", $useStatements)."\n" : '';

            // Build traits
            $traits = [];

            if (in_array('seeder', $generateConfig, true)) {
                $traits[] = 'HasFactory';
            }

            if ($softDeletes) {
                $traits[] = 'SoftDeletes';
            }

            $traitsString = ! empty($traits) ? "\n    use ".implode(', ', $traits).';' : '';

            // Build primary key configuration
            $primaryKeyConfig = '';
            if ($primaryKey !== 'id') {
                $primaryKeyConfig = "\n    protected \$primaryKey = '{$primaryKey}';";
                // Check if it's a UUID or non-incrementing key
                if ($primaryKey === 'uuid' || ! str_ends_with($primaryKey, '_id')) {
                    $primaryKeyConfig .= "\n    public \$incrementing = false;";
                    if ($primaryKey === 'uuid') {
                        $primaryKeyConfig .= "\n    protected \$keyType = 'string';";
                    }
                }
            }

            $modelContent = str_replace([
                '{{ model }}',
                '{{ fillable }}',
                '{{ relations }}',
                '{{ casts }}',
                '{{ getter }}',
                '{{ setter }}',
                '{{ use_statements }}',
                '{{ traits }}',
                '{{ primary_key }}',
            ], [
                $modelName,
                $fillableArray,
                $relationshipMethods,
                $casts,
                $getters,
                $setters,
                $useStatementsString,
                $traitsString,
                $primaryKeyConfig,
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
