<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateRequestService
{
    private GenerateModuleFromYaml $command;

    private array $generateConfig;

    public array $allModels;

    private StubPathResolverService $pathResolverService;

    public function __construct(GenerateModuleFromYaml $command, array $allModels, array $generateConfig)
    {
        $this->command = $command;
        $this->allModels = $allModels;
        $this->generateConfig = $generateConfig;
        $this->pathResolverService = new StubPathResolverService;
    }

    /**
     * Handle request file generation
     */
    public function handleRequestGeneration(array $modelConfig, bool $force): void
    {
        if (in_array('request', $this->generateConfig, true)) {
            $requestPath = app_path("Http/Requests/{$modelConfig['classes']['request']}.php");

            if (File::exists($requestPath) && ! $force) {
                $this->command->warn("⚠️ Request already exists: {$modelConfig['classes']['request']}");

                return;
            }

            if (File::exists($requestPath)) {
                File::delete($requestPath);
                $this->command->warn("⚠️ Deleted existing request: {$modelConfig['classes']['request']}");
            }

            $this->generateRequest($modelConfig['studlyName'], $modelConfig['fields'], $modelConfig['originalName']);
        }
    }

    /**
     * Generate form request with validation rules
     */
    protected function generateRequest(string $modelName, array $fields, ?string $originalModelName = null): void
    {
        $originalModelName = $originalModelName ?? $modelName;

        $requestClass = "{$modelName}Request";
        $requestPath = app_path("Http/Requests/{$requestClass}.php");
        $stubPath = $this->pathResolverService->resolveStubPath('request');

        if (! File::exists($stubPath)) {
            $this->command->error("Request stub not found: {$stubPath}");

            return;
        }

        $rulesFormatted = $this->buildValidationRulesWithRelations($originalModelName, $fields);
        $this->createRequestFile($stubPath, $requestPath, $modelName, $rulesFormatted);

        $this->command->info("🤫 Form Request created with validation: {$requestClass}");
    }

    /**
     * Build validation rules for request including nested relations
     */
    private function buildValidationRulesWithRelations(string $modelName, array $fields): string
    {
        $rules = [];

        // Get current model's validation rules
        foreach ($fields as $name => $definition) {
            $rules[$name] = $this->generateFieldValidationRule($name, $definition);
        }

        // Add validation rules for nested relations
        $relationRules = $this->getRelationValidationRules($modelName);
        if (! empty($relationRules)) {
            $rules = array_merge($rules, $relationRules);
        }

        return $this->formatValidationRules($rules);
    }

    /**
     * Get validation rules for nested relations
     */
    private function getRelationValidationRules(string $modelName, string $prefix = ''): array
    {
        $relationRules = [];

        // Find the current model data
        $currentModelData = null;
        $currentModelKey = null;

        foreach ($this->allModels as $key => $data) {
            if (Str::studly($key) === $modelName) {
                $currentModelData = $data;
                $currentModelKey = $key;
                break;
            }
        }

        if (! $currentModelData || ! isset($currentModelData['nested_requests'])) {
            return $relationRules;
        }

        // Get nested request relations
        $nestedRequests = is_array($currentModelData['nested_requests'])
            ? $currentModelData['nested_requests']
            : array_map('trim', explode(',', $currentModelData['nested_requests']));

        // Parse relations from new structure
        $relations = $this->parseRelations($currentModelData['relations'] ?? []);

        foreach ($nestedRequests as $relationName) {
            $relationName = trim($relationName);

            // Find the relation configuration
            $relationConfig = null;
            foreach ($relations as $relName => $relConfig) {
                if ($relName === $relationName) {
                    $relationConfig = $relConfig;
                    break;
                }
            }

            if (! $relationConfig) {
                $this->command->warn("⚠️ Nested request relation '{$relationName}' not found in relations for {$modelName}");

                continue;
            }

            // Only process hasMany and hasOne relations
            if (! in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                $this->command->warn("⚠️ Nested request '{$relationName}' must be hasMany or hasOne relation");

                continue;
            }

            $relatedModelName = $relationConfig['model'];

            // Find related model data
            $relatedModelData = null;
            foreach ($this->allModels as $key => $data) {
                if (Str::studly($key) === $relatedModelName) {
                    $relatedModelData = $data;
                    break;
                }
            }

            if (! $relatedModelData) {
                $this->command->warn("⚠️ Related model '{$relatedModelName}' not found in YAML configuration");

                continue;
            }

            // Generate validation rules based on relation type
            if ($relationConfig['type'] === 'hasMany') {
                $arrayName = Str::snake($relationName);
                $currentPrefix = $prefix ? $prefix.'.' : '';
                $fullArrayPath = $currentPrefix.$arrayName;

                $relationRules[$fullArrayPath] = 'nullable|array';
                $relationRules["{$fullArrayPath}.*"] = 'required|array';

                // Add field validations
                if (isset($relatedModelData['fields'])) {
                    foreach ($relatedModelData['fields'] as $fieldName => $fieldDefinition) {
                        // Skip foreign key to parent
                        $parentForeignKey = Str::snake($currentModelKey).'_id';
                        if ($fieldName === $parentForeignKey) {
                            continue;
                        }

                        $validationRule = $this->generateFieldValidationRule($fieldName, $fieldDefinition);
                        $relationRules["{$fullArrayPath}.*.{$fieldName}"] = $validationRule;
                    }
                }
            } else { // hasOne
                $objectName = Str::snake($relationName);
                $currentPrefix = $prefix ? $prefix.'.' : '';
                $fullObjectPath = $currentPrefix.$objectName;

                $relationRules[$fullObjectPath] = 'nullable|array';

                // Add field validations
                if (isset($relatedModelData['fields'])) {
                    foreach ($relatedModelData['fields'] as $fieldName => $fieldDefinition) {
                        $parentForeignKey = Str::snake($currentModelKey).'_id';
                        if ($fieldName === $parentForeignKey) {
                            continue;
                        }

                        $validationRule = $this->generateFieldValidationRule($fieldName, $fieldDefinition);
                        $relationRules["{$fullObjectPath}.{$fieldName}"] = $validationRule;
                    }
                }
            }
        }

        return $relationRules;
    }

    /**
     * Parse relations from new YAML structure
     */
    private function parseRelations(array $relationsData): array
    {
        $relations = [];

        foreach ($relationsData as $relationType => $relationsList) {
            if (! is_string($relationsList)) {
                continue;
            }

            // Split by comma to get individual relations
            $relationItems = array_map('trim', explode(',', $relationsList));

            foreach ($relationItems as $relationDefinition) {
                // Parse "Model:relationName" format or just "Model"
                $parts = explode(':', trim($relationDefinition));
                $model = trim($parts[0]);

                if (isset($parts[1])) {
                    $name = trim($parts[1]);
                } else {
                    // Default name based on relation type
                    if ($relationType === 'hasMany') {
                        $name = Str::camel(Str::plural($model));
                    } elseif ($relationType === 'belongsToMany') {
                        $name = Str::camel(Str::plural($model));
                    } else {
                        $name = Str::camel($model);
                    }
                }

                $relations[$name] = [
                    'type' => $relationType,
                    'model' => $model,
                ];
            }
        }

        return $relations;
    }

    /**
     * Generate validation rule for a single field
     */
    private function generateFieldValidationRule(string $name, string $definition): string
    {
        $parts = explode(':', $definition);
        $type = array_shift($parts);
        $isNullable = in_array('nullable', $parts);

        $ruleSet = [$isNullable ? 'nullable' : 'required'];

        switch ($type) {
            case 'image':
                $ruleSet[] = 'image';
                $ruleSet[] = 'mimes:jpeg,jpg,png,gif,webp,svg';
                $ruleSet[] = 'max:2048';
                break;
            case 'file':
                $ruleSet[] = 'file';
                $ruleSet[] = 'max:10240';
                break;
            case 'string':
            case 'text':
                $ruleSet[] = 'string';
                break;
            case 'integer':
                $ruleSet[] = 'integer';
                break;
            case 'decimal':
            case 'double':
                $ruleSet[] = 'numeric';
                break;
            case 'boolean':
                $ruleSet[] = 'boolean';
                break;
            case 'date':
            case 'dateTime':
            case 'timestamp':
                $ruleSet[] = 'date';
                break;
            case 'json':
                $ruleSet[] = 'array';
                break;
            case 'foreignId':
                $relatedTable = $parts[0] ?? Str::snake(Str::pluralStudly(Str::beforeLast($name, '_id')));
                $ruleSet[] = 'exists:'.$relatedTable.',id';
                break;
        }

        return implode('|', $ruleSet);
    }

    /**
     * Format validation rules as string
     */
    private function formatValidationRules(array $rules): string
    {
        $rulesFormatted = '';
        $first = true;

        foreach ($rules as $field => $rule) {
            if ($first) {
                $rulesFormatted .= "'{$field}' => '{$rule}',\n";
                $first = false;
            } else {
                $rulesFormatted .= "            '{$field}' => '{$rule}',\n";
            }
        }

        return rtrim($rulesFormatted, "\n");
    }

    /**
     * Create request file from stub
     */
    private function createRequestFile(string $stubPath, string $requestPath, string $modelName, string $rulesFormatted): void
    {
        $stub = File::get($stubPath);
        $stub = str_replace(
            ['{{ model }}', '{{ rules }}'],
            [$modelName, $rulesFormatted],
            $stub
        );

        $directory = dirname($requestPath);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($requestPath, $stub);
    }
}
