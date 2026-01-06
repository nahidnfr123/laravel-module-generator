<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateRequestService
{
    private GenerateModuleFromYaml $command;

    private array $generateConfig;

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
        if ($this->generateConfig['request']) {
            $requestPath = app_path("Http/Requests/{$modelConfig['classes']['request']}.php");

            if (File::exists($requestPath) && ! $force) {
                $this->command->warn("⚠️ Request already exists: {$modelConfig['classes']['request']}");

                return;
            }

            if (File::exists($requestPath)) {
                File::delete($requestPath);
                $this->command->warn("⚠️ Deleted existing request: {$modelConfig['classes']['request']}");
            }

            $this->generateRequest($modelConfig['studlyName'], $modelConfig['fields']);
        }
    }

    /**
     * Generate form request with validation rules
     */
    protected function generateRequest(string $modelName, array $fields, ?string $originalModelName = null): void
    {
        // If originalModelName is not provided, use modelName (backward compatibility)
        $originalModelName = $originalModelName ?? $modelName;

        $requestClass = "{$modelName}Request";
        $requestPath = app_path("Http/Requests/{$requestClass}.php");
        $stubPath = $this->pathResolverService->resolveStubPath('request');

        if (! File::exists($stubPath)) {
            $this->command->error("Request stub not found: {$stubPath}");

            return;
        }

        // Get validation rules including nested relations with makeRequest: true
        $rulesFormatted = $this->buildValidationRulesWithRelations($originalModelName, $fields);
        $this->createRequestFile($stubPath, $requestPath, $modelName, $rulesFormatted);

        $this->command->info("🤫 Form Request created with validation: {$requestClass}");
    }

    /**
     * Build validation rules for request including nested relations with makeRequest: true
     */
    private function buildValidationRulesWithRelations(string $modelName, array $fields): string
    {
        $rules = [];

        // Get current model's validation rules
        foreach ($fields as $name => $definition) {
            $rules[$name] = $this->generateFieldValidationRule($name, $definition);
        }

        // Add validation rules for relations with makeRequest: true
        $relationRules = $this->getRelationValidationRules($modelName);
        if (! empty($relationRules)) {
            $rules = array_merge($rules, $relationRules);
        }

        return $this->formatValidationRules($rules);
    }

    /**
     * Get validation rules for relations marked with makeRequest: true
     */
    private function getRelationValidationRules(string $modelName, string $prefix = ''): array
    {
        $models = $this->allModels;
        $relationRules = [];

        // Check if current model has relations defined
        if (! isset($models[$modelName]['relations'])) {
            return $relationRules;
        }

        $relations = $models[$modelName]['relations'];

        foreach ($relations as $relationName => $relationConfig) {
            // Skip if makeRequest is not true
            if (! isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            // Only process hasMany and hasOne relations (not belongsTo)
            if (! in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                continue;
            }

            $relatedModelName = $relationConfig['model'];

            // Check if related model exists in YAML
            if (! isset($models[$relatedModelName])) {
                $this->command->warn("⚠️ Related model '{$relatedModelName}' not found in YAML configuration");

                continue;
            }

            $relatedModelData = $models[$relatedModelName];

            // Generate the array name based on relation type
            if ($relationConfig['type'] === 'hasMany') {
                $arrayName = Str::snake($relationName); // Use the relation name directly
                $currentPrefix = $prefix ? $prefix.'.' : '';
                $fullArrayPath = $currentPrefix.$arrayName;

                // Add validation for the array itself
                $relationRules[$fullArrayPath] = 'nullable|array';
                $relationRules["{$fullArrayPath}.*"] = 'required|array';

            } else { // hasOne
                $objectName = Str::snake($relationName);
                $currentPrefix = $prefix ? $prefix.'.' : '';
                $fullObjectPath = $currentPrefix.$objectName;

                // Add validation for the object itself
                $relationRules[$fullObjectPath] = 'nullable|array';
            }

            // Add validation for each field in the related model
            if (isset($relatedModelData['fields'])) {
                foreach ($relatedModelData['fields'] as $fieldName => $fieldDefinition) {
                    // Skip foreign key fields that reference the parent
                    $parentForeignKey = Str::snake($modelName).'_id';
                    if ($fieldName === $parentForeignKey) {
                        continue;
                    }

                    $validationRule = $this->generateFieldValidationRule($fieldName, $fieldDefinition);

                    if ($relationConfig['type'] === 'hasMany') {
                        $relationRules["{$fullArrayPath}.*.{$fieldName}"] = $validationRule;
                    } else { // hasOne
                        $relationRules["{$fullObjectPath}.{$fieldName}"] = $validationRule;
                    }
                }
            }

            // Recursively get validation rules for nested relations
            $nestedRules = $this->getRelationValidationRules(
                $relatedModelName,
                $relationConfig['type'] === 'hasMany' ? "{$fullArrayPath}.*" : $fullObjectPath
            );

            if (! empty($nestedRules)) {
                $relationRules = array_merge($relationRules, $nestedRules);
            }
        }

        return $relationRules;
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
                $ruleSet[] = 'max:2048'; // 2MB max size
                break;
            case 'file':
                $ruleSet[] = 'file';
                $ruleSet[] = 'max:10240'; // 10MB max size
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

        // Ensure the directory exists
        $directory = dirname($requestPath);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($requestPath, $stub);
    }
}
