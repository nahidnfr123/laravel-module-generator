<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateControllerService
{
    private GenerateModuleFromYaml $command;
    //    private Command $command;

    private array $allModels;

    private StubPathResolverService $pathResolverService;

    public function __construct(GenerateModuleFromYaml $command, array $allModels)
    {
        $this->command = $command;
        $this->allModels = $allModels;
        $this->pathResolverService = new StubPathResolverService;
    }

    /**
     * Generate controller with relation handling
     */
    public function generateController(array $modelConfig, array $modelData): void
    {
        $hasRelationsWithRequest = $this->hasRelationsWithMakeRequest($modelData);
        $generateService = $modelData['generate']['service'] ?? true;

        $controllerClass = $modelConfig['classes']['controller'];
        $path = app_path("Http/Controllers/{$controllerClass}.php");

        // Determine which stub to use based on service and relations
        $stubKey = $this->determineControllerStub($generateService, $hasRelationsWithRequest);
        $stubPath = $this->pathResolverService->resolveStubPath($stubKey);

        if (! File::exists($stubPath)) {
            $this->command->error("Controller stub not found: {$stubPath}");

            return;
        }

        File::ensureDirectoryExists(app_path('Http/Controllers'));

        $stubContent = File::get($stubPath);
        $stubContent = $this->replaceStubPlaceholders($stubContent, $modelConfig, $modelData, $generateService);

        File::put($path, $stubContent);
        $this->command->info("🤫 Controller created: {$controllerClass}");
    }

    /**
     * Generate service with relation handling
     */
    public function generateService(array $modelConfig, array $modelData): void
    {
        $hasRelationsWithRequest = $this->hasRelationsWithMakeRequest($modelData);

        $serviceClass = $modelConfig['classes']['service'];
        $serviceDir = app_path('Services');
        $path = "{$serviceDir}/{$serviceClass}.php";

        // Determine which stub to use
        $stubKey = $hasRelationsWithRequest ? 'service-relation' : 'service';
        $stubPath = $this->pathResolverService->resolveStubPath($stubKey);

        if (! File::exists($stubPath)) {
            $this->command->error("Service stub not found: {$stubPath}");

            return;
        }

        File::ensureDirectoryExists($serviceDir);

        $stubContent = File::get($stubPath);
        $stubContent = $this->replaceStubPlaceholders($stubContent, $modelConfig, $modelData, true);

        File::put($path, $stubContent);
        $this->command->info("🤫 Service created: {$serviceClass}");
    }

    /**
     * Determine which controller stub to use
     */
    private function determineControllerStub(bool $generateService, bool $hasRelationsWithRequest): string
    {
        if (! $generateService && $hasRelationsWithRequest) {
            return 'controller-without-service';
        }

        if (! $generateService) {
            return 'controller-without-service';
        }

        if ($hasRelationsWithRequest) {
            return 'controller-relation';
        }

        return 'controller';
    }

    /**
     * Check if model has relations with makeRequest: true
     */
    private function hasRelationsWithMakeRequest(array $modelData): bool
    {
        if (! isset($modelData['relations'])) {
            return false;
        }

        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (isset($relationConfig['makeRequest']) && $relationConfig['makeRequest'] === true) {
                if (in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Replace stub placeholders with actual values
     */
    private function replaceStubPlaceholders(string $stubContent, array $modelConfig, array $modelData, bool $generateService = true): string
    {
        $replacements = [
            '{{ class }}' => $modelConfig['classes']['controller'],
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ modelPlural }}' => $modelConfig['pluralStudlyName'],
            '{{ route }}' => $modelConfig['tableName'],
            '{{ relationStore }}' => $this->generateRelationStoreCode($modelData, $modelConfig['camelName']),
            '{{ relationUpdate }}' => $this->generateRelationUpdateCode($modelData, $modelConfig['camelName']),
            '{{ relationImports }}' => $this->generateRelationImports($modelData),
            '{{ with }}' => $this->generateWithRelations($modelData),
            '{{ serviceUsage }}' => $generateService ? 'service' : 'model',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Generate with relations for eager loading
     */
    private function generateWithRelations(array $modelData): string
    {
        if (! isset($modelData['relations'])) {
            return '';
        }

        $relations = [];
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (isset($relationConfig['makeRequest']) && $relationConfig['makeRequest'] === true) {
                if (in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                    $relations[] = "'{$relationName}'";
                }
            }
        }

        return implode(', ', $relations);
    }

    /**
     * Generate relation store code
     */
    private function generateRelationStoreCode(array $modelData, string $modelVariable): string
    {
        if (! isset($modelData['relations'])) {
            return '';
        }

        $code = '';
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (! isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            if (! in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                continue;
            }

            $relatedModel = $relationConfig['model'];
            $relationKey = Str::snake($relationName);

            if ($relationConfig['type'] === 'hasMany') {
                $code .= $this->generateHasManyStoreCode($relationName, $relationKey, $modelVariable, $relatedModel);
            } else { // hasOne
                $code .= $this->generateHasOneStoreCode($relationName, $relationKey, $modelVariable, $relatedModel);
            }
        }

        return $code;
    }

    /**
     * Generate hasMany store code with nested relations
     */
    private function generateHasManyStoreCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        $nestedRelationCode = $this->generateNestedRelationCode($relatedModel, '$created'.Str::studly($relationName));

        return "
            // Handle {$relationName} relation
            if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {
                foreach (\$validatedData['{$relationKey}'] as \$relationData) {
                    if (isset(\$relationData['id'])) {
                        \$existing{$relationName} = \${$modelVariable}->{$relationName}()->find(\$relationData['id']);
                        if (\$existing{$relationName}) {
                            \$existing{$relationName}->fill(\$relationData);
                            \$existing{$relationName}->save();
{$this->generateNestedRelationCode($relatedModel, '$existing'.$relationName, 4)}
                        }
                    } else {
                        \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\$relationData);
{$this->generateNestedRelationCode($relatedModel, '$created'.$relationName, 4)}
                    }
                }
                unset(\$validatedData['{$relationKey}']);
            }
";
    }

    /**
     * Generate hasOne store code with nested relations
     */
    private function generateHasOneStoreCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        return "
            // Handle {$relationName} relation
            if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {
                \$related{$relationName} = \${$modelVariable}->{$relationName};
                if (\$related{$relationName}) {
                    \$related{$relationName}->fill(\$validatedData['{$relationKey}']);
                    \$related{$relationName}->save();
{$this->generateNestedRelationCode($relatedModel, '$related'.$relationName, 4)}
                } else {
                    \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\$validatedData['{$relationKey}']);
{$this->generateNestedRelationCode($relatedModel, '$created'.$relationName, 4)}
                }
                unset(\$validatedData['{$relationKey}']);
            }
";
    }

    /**
     * Generate nested relation code for deeper relations
     */
    private function generateNestedRelationCode(string $modelName, string $parentVariable, int $indentLevel = 3): string
    {
        if (! isset($this->allModels[$modelName]['relations'])) {
            return '';
        }

        $indent = str_repeat('    ', $indentLevel);
        $code = '';

        foreach ($this->allModels[$modelName]['relations'] as $relationName => $relationConfig) {
            if (! isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            if (! in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                continue;
            }

            $relationKey = Str::snake($relationName);
            $relatedModel = $relationConfig['model'];

            if ($relationConfig['type'] === 'hasMany') {
                $code .= "\n{$indent}// Handle nested {$relationName} relation";
                $code .= "\n{$indent}if (isset(\$relationData['{$relationKey}']) && is_array(\$relationData['{$relationKey}'])) {";
                $code .= "\n{$indent}    foreach (\$relationData['{$relationKey}'] as \$nestedData) {";
                $code .= "\n{$indent}        if (isset(\$nestedData['id'])) {";
                $code .= "\n{$indent}            \$nested = {$parentVariable}->{$relationName}()->find(\$nestedData['id']);";
                $code .= "\n{$indent}            if (\$nested) {";
                $code .= "\n{$indent}                \$nested->fill(\$nestedData);";
                $code .= "\n{$indent}                \$nested->save();";
                $code .= "\n{$indent}            }";
                $code .= "\n{$indent}        } else {";
                $code .= "\n{$indent}            {$parentVariable}->{$relationName}()->create(\$nestedData);";
                $code .= "\n{$indent}        }";
                $code .= "\n{$indent}    }";
                $code .= "\n{$indent}}";
            } else { // hasOne
                $code .= "\n{$indent}// Handle nested {$relationName} relation";
                $code .= "\n{$indent}if (isset(\$relationData['{$relationKey}']) && is_array(\$relationData['{$relationKey}'])) {";
                $code .= "\n{$indent}    \$nested{$relationName} = {$parentVariable}->{$relationName};";
                $code .= "\n{$indent}    if (\$nested{$relationName}) {";
                $code .= "\n{$indent}        \$nested{$relationName}->fill(\$relationData['{$relationKey}']);";
                $code .= "\n{$indent}        \$nested{$relationName}->save();";
                $code .= "\n{$indent}    } else {";
                $code .= "\n{$indent}        {$parentVariable}->{$relationName}()->create(\$relationData['{$relationKey}']);";
                $code .= "\n{$indent}    }";
                $code .= "\n{$indent}}";
            }
        }

        return $code;
    }

    /**
     * Generate relation update code
     */
    private function generateRelationUpdateCode(array $modelData, string $modelVariable): string
    {
        if (! isset($modelData['relations'])) {
            return '';
        }

        $code = '';
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (! isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            if (! in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                continue;
            }

            $relatedModel = $relationConfig['model'];
            $relationKey = Str::snake($relationName);

            if ($relationConfig['type'] === 'hasMany') {
                $code .= $this->generateHasManyUpdateCode($relationName, $relationKey, $modelVariable, $relatedModel);
            } else { // hasOne
                $code .= $this->generateHasOneUpdateCode($relationName, $relationKey, $modelVariable, $relatedModel);
            }
        }

        return $code;
    }

    /**
     * Generate hasMany update code with nested relations
     */
    private function generateHasManyUpdateCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        return "
            // Handle {$relationName} relation update
            if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {
                foreach (\$validatedData['{$relationKey}'] as \$relationData) {
                    if (isset(\$relationData['id'])) {
                        \$existing{$relationName} = \${$modelVariable}->{$relationName}()->find(\$relationData['id']);
                        if (\$existing{$relationName}) {
                            \$existing{$relationName}->fill(\$relationData);
                            \$existing{$relationName}->save();
{$this->generateNestedRelationCode($relatedModel, '$existing'.$relationName, 4)}
                        }
                    } else {
                        \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\$relationData);
{$this->generateNestedRelationCode($relatedModel, '$created'.$relationName, 4)}
                    }
                }
                unset(\$validatedData['{$relationKey}']);
            }
";
    }

    /**
     * Generate hasOne update code with nested relations
     */
    private function generateHasOneUpdateCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        return "
            // Handle {$relationName} relation update
            if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {
                \$related{$relationName} = \${$modelVariable}->{$relationName};
                if (\$related{$relationName}) {
                    \$related{$relationName}->fill(\$validatedData['{$relationKey}']);
                    \$related{$relationName}->save();
{$this->generateNestedRelationCode($relatedModel, '$related'.$relationName, 4)}
                } else {
                    \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\$validatedData['{$relationKey}']);
{$this->generateNestedRelationCode($relatedModel, '$created'.$relationName, 4)}
                }
                unset(\$validatedData['{$relationKey}']);
            }
";
    }

    /**
     * Generate relation imports
     */
    private function generateRelationImports(array $modelData): string
    {
        if (! isset($modelData['relations'])) {
            return '';
        }

        $imports = [];
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (! isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            if (in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                $relatedModel = $relationConfig['model'];
                $imports[] = "use App\\Models\\{$relatedModel};";

                // Also add imports for nested relations
                $nestedImports = $this->getNestedRelationImports($relatedModel);
                $imports = array_merge($imports, $nestedImports);
            }
        }

        return empty($imports) ? '' : implode("\n", array_unique($imports))."\n";
    }

    /**
     * Get imports for nested relations
     */
    private function getNestedRelationImports(string $modelName): array
    {
        $imports = [];

        if (! isset($this->allModels[$modelName]['relations'])) {
            return $imports;
        }

        foreach ($this->allModels[$modelName]['relations'] as $relationName => $relationConfig) {
            if (! isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            if (in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                $relatedModel = $relationConfig['model'];
                $imports[] = "use App\\Models\\{$relatedModel};";

                // Recursively get nested imports (prevent infinite loops by tracking visited models)
                $nestedImports = $this->getNestedRelationImports($relatedModel);
                $imports = array_merge($imports, $nestedImports);
            }
        }

        return $imports;
    }
}
