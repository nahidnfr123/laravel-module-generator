<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateControllerServiceBackup2
{
    private GenerateModuleFromYaml $command;
    private array $allModels;
    private StubPathResolverService $stubPathResolver;

    public function __construct(GenerateModuleFromYaml $command, array $allModels)
    {
        $this->command = $command;
        $this->allModels = $allModels;
        $this->stubPathResolver = new StubPathResolverService();
    }

    /**
     * Generate controller based on model configuration
     */
    public function generateController(array $modelConfig, array $modelData): void
    {
        $controllerClass = $modelConfig['classes']['controller'];
        $path = app_path("Http/Controllers/{$controllerClass}.php");

        $stubKey = $this->determineControllerStub($modelData);
        $stubPath = $this->stubPathResolver->resolveStubPath($stubKey);

        if (!File::exists($stubPath)) {
            $this->command->error("Controller stub not found: {$stubPath}");
            return;
        }

        File::ensureDirectoryExists(app_path('Http/Controllers'));

        $stubContent = File::get($stubPath);
        $stubContent = $this->replaceControllerPlaceholders($stubContent, $modelConfig, $modelData);

        File::put($path, $stubContent);
        $this->command->info("🎮 Controller created: {$controllerClass}");
    }

    /**
     * Generate service based on model configuration
     */
    public function generateService(array $modelConfig, array $modelData): void
    {
        $serviceClass = $modelConfig['classes']['service'];
        $serviceDir = app_path('Services');
        $path = "{$serviceDir}/{$serviceClass}.php";

        $stubKey = $this->determineServiceStub($modelData);
        $stubPath = $this->stubPathResolver->resolveStubPath($stubKey);

        if (!File::exists($stubPath)) {
            $this->command->error("Service stub not found: {$stubPath}");
            return;
        }

        File::ensureDirectoryExists($serviceDir);

        $stubContent = File::get($stubPath);
        $stubContent = $this->replaceServicePlaceholders($stubContent, $modelConfig, $modelData);

        File::put($path, $stubContent);
        $this->command->info("🔧 Service created: {$serviceClass}");
    }

    /**
     * Determine which controller stub to use based on model configuration
     */
    private function determineControllerStub(array $modelData): string
    {
        $generateService = $modelData['generate']['service'] ?? true;
        $hasApiResources = $this->hasApiResources($modelData);
        $hasMiddleware = $this->hasMiddleware($modelData);
        $hasRelations = $this->hasRelationsWithRequest($modelData);

        // Use different stubs based on features
        if (!$generateService && $hasRelations) {
            return 'controller-without-service-relations';
        }

        if (!$generateService) {
            return 'controller-without-service';
        }

        if ($hasRelations) {
            return 'controller-with-relations';
        }

        return 'controller';
    }

    /**
     * Determine which service stub to use based on model configuration
     */
    private function determineServiceStub(array $modelData): string
    {
        $hasRelations = $this->hasRelationsWithRequest($modelData);
        $hasRepository = $modelData['generate']['repository'] ?? false;

        if ($hasRepository && $hasRelations) {
            return 'service-repository-relations';
        }

        if ($hasRepository) {
            return 'service-repository';
        }

        if ($hasRelations) {
            return 'service-relations';
        }

        return 'service';
    }

    /**
     * Replace controller stub placeholders
     */
    private function replaceControllerPlaceholders(string $stubContent, array $modelConfig, array $modelData): string
    {
        $replacements = [
            '{{ class }}' => $modelConfig['classes']['controller'],
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ modelPlural }}' => $modelConfig['pluralStudlyName'],
            '{{ route }}' => $modelConfig['tableName'],
            '{{ serviceUsage }}' => $this->getServiceUsage($modelData),
            '{{ middlewareDefinition }}' => $this->generateMiddlewareDefinition($modelData, $modelConfig['tableName']),
            '{{ imports }}' => $this->generateControllerImports($modelData, $modelConfig),
            '{{ with }}' => $this->generateWithRelations($modelData),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Replace service stub placeholders
     */
    private function replaceServicePlaceholders(string $stubContent, array $modelConfig, array $modelData): string
    {
        $replacements = [
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ relationImports }}' => $this->generateRelationImports($modelData),
            '{{ with }}' => $this->generateWithRelations($modelData),
            '{{ relationStore }}' => $this->generateRelationStoreCode($modelData, $modelConfig['camelName']),
            '{{ relationUpdate }}' => $this->generateRelationUpdateCode($modelData, $modelConfig['camelName']),
            '{{ repositoryUsage }}' => $this->getRepositoryUsage($modelData),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Generate controller imports based on configuration
     */
    private function generateControllerImports(array $modelData, array $modelConfig): string
    {
        $imports = [];
        $modelName = $modelConfig['studlyName'];

        // Always include basic imports
        $imports[] = "use App\\Http\\Requests\\{$modelName}Request;";
        $imports[] = "use App\\Models\\{$modelName};";

        // Conditional imports
        if ($this->hasApiResources($modelData)) {
            $imports[] = "use App\\Http\\Resources\\{$modelName}\\{$modelName}Collection;";
            $imports[] = "use App\\Http\\Resources\\{$modelName}\\{$modelName}Resource;";
        }

        if ($modelData['generate']['service'] ?? true) {
            $imports[] = "use App\\Services\\{$modelName}Service;";
        }

        if ($modelData['generate']['repository'] ?? false) {
            $imports[] = "use App\\Repositories\\{$modelName}Repository;";
        }

        if ($this->hasMiddleware($modelData)) {
            $imports[] = "use Illuminate\\Routing\\Controllers\\HasMiddleware;";
            $imports[] = "use Illuminate\\Routing\\Controllers\\Middleware;";
        }

        // Add relation imports
        $relationImports = $this->generateRelationImports($modelData);
        if ($relationImports) {
            $imports[] = $relationImports;
        }

        return implode("\n", array_unique($imports));
    }

    /**
     * Generate middleware definition
     */
    private function generateMiddlewareDefinition(array $modelData, string $route): string
    {
        if (!$this->hasMiddleware($modelData)) {
            return '';
        }

        $middlewares = $modelData['middleware'] ?? ['auth'];
        $permissions = $modelData['permissions'] ?? true;

        $middlewareCode = "public static function middleware(): array\n    {\n";

        if ($permissions === true) {
            $middlewareCode .= "        \$model = '{$route}';\n\n";
            $middlewareCode .= "        return [\n";

            foreach ($middlewares as $middleware) {
                $middlewareCode .= "            '{$middleware}',\n";
            }

            $middlewareCode .= "            new Middleware([\"permission:view_\$model\"], only: ['index']),\n";
            $middlewareCode .= "            new Middleware([\"permission:show_\$model\"], only: ['show']),\n";
            $middlewareCode .= "            new Middleware([\"permission:create_\$model\"], only: ['store']),\n";
            $middlewareCode .= "            new Middleware([\"permission:update_\$model\"], only: ['update']),\n";
            $middlewareCode .= "            new Middleware([\"permission:delete_\$model\"], only: ['destroy']),\n";
            $middlewareCode .= "        ];\n";
        } else {
            $middlewareCode .= "        return [\n";
            foreach ($middlewares as $middleware) {
                $middlewareCode .= "            '{$middleware}',\n";
            }
            $middlewareCode .= "        ];\n";
        }

        $middlewareCode .= "    }";

        return $middlewareCode;
    }

    /**
     * Generate with relations for eager loading
     */
    private function generateWithRelations(array $modelData): string
    {
        if (!isset($modelData['relations'])) {
            return '';
        }

        $relations = [];
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            // Include all relations for eager loading, not just those with makeRequest
            if ($this->shouldEagerLoad($relationConfig)) {
                $relations[] = "'{$relationName}'";
            }
        }

        return implode(', ', $relations);
    }

    /**
     * Generate relation store code for nested relations
     */
    private function generateRelationStoreCode(array $modelData, string $modelVariable): string
    {
        if (!isset($modelData['relations'])) {
            return '';
        }

        $code = '';
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (!$this->shouldProcessRelation($relationConfig)) {
                continue;
            }

            $code .= $this->generateRelationStoreMethod($relationName, $relationConfig, $modelVariable);
        }

        return $code;
    }

    /**
     * Generate relation update code for nested relations
     */
    private function generateRelationUpdateCode(array $modelData, string $modelVariable): string
    {
        if (!isset($modelData['relations'])) {
            return '';
        }

        $code = '';
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (!$this->shouldProcessRelation($relationConfig)) {
                continue;
            }

            $code .= $this->generateRelationUpdateMethod($relationName, $relationConfig, $modelVariable);
        }

        return $code;
    }

    /**
     * Generate store method for specific relation
     */
    private function generateRelationStoreMethod(string $relationName, array $relationConfig, string $modelVariable): string
    {
        $relationKey = Str::snake($relationName);
        $relatedModel = $relationConfig['model'];
        $relationType = $relationConfig['type'];

        switch ($relationType) {
            case 'hasMany':
                return $this->generateHasManyStoreCode($relationName, $relationKey, $modelVariable, $relatedModel);
            case 'hasOne':
                return $this->generateHasOneStoreCode($relationName, $relationKey, $modelVariable, $relatedModel);
            case 'belongsToMany':
                return $this->generateBelongsToManyStoreCode($relationName, $relationKey, $modelVariable);
            default:
                return '';
        }
    }

    /**
     * Generate update method for specific relation
     */
    private function generateRelationUpdateMethod(string $relationName, array $relationConfig, string $modelVariable): string
    {
        $relationKey = Str::snake($relationName);
        $relatedModel = $relationConfig['model'];
        $relationType = $relationConfig['type'];

        switch ($relationType) {
            case 'hasMany':
                return $this->generateHasManyUpdateCode($relationName, $relationKey, $modelVariable, $relatedModel);
            case 'hasOne':
                return $this->generateHasOneUpdateCode($relationName, $relationKey, $modelVariable, $relatedModel);
            case 'belongsToMany':
                return $this->generateBelongsToManyUpdateCode($relationName, $relationKey, $modelVariable);
            default:
                return '';
        }
    }

    /**
     * Generate hasMany store code
     */
    private function generateHasManyStoreCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        $nestedCode = $this->generateNestedRelationHandling($relatedModel, $relationName . 'Data', 3);

        return "
        // Handle {$relationName} relation
        if (isset(\$request['{$relationKey}']) && is_array(\$request['{$relationKey}'])) {
            foreach (\$request['{$relationKey}'] as \${$relationName}Data) {
                \${$relationName}Request = collect(\${$relationName}Data)->except([{$this->getNestedRelationKeys($relatedModel)}])->toArray();
                \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\${$relationName}Request);
{$nestedCode}
            }
        }";
    }

    /**
     * Generate hasOne store code
     */
    private function generateHasOneStoreCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        $nestedCode = $this->generateNestedRelationHandling($relatedModel, $relationName . 'Data', 3);

        return "
        // Handle {$relationName} relation
        if (isset(\$request['{$relationKey}'])) {
            \${$relationName}Data = \$request['{$relationKey}'];
            \${$relationName}Request = collect(\${$relationName}Data)->except([{$this->getNestedRelationKeys($relatedModel)}])->toArray();
            \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\${$relationName}Request);
{$nestedCode}
        }";
    }

    /**
     * Generate belongsToMany store code
     */
    private function generateBelongsToManyStoreCode(string $relationName, string $relationKey, string $modelVariable): string
    {
        return "
        // Handle {$relationName} relation (Many-to-Many)
        if (isset(\$request['{$relationKey}']) && is_array(\$request['{$relationKey}'])) {
            \${$modelVariable}->{$relationName}()->sync(\$request['{$relationKey}']);
        }";
    }

    /**
     * Generate hasMany update code
     */
    private function generateHasManyUpdateCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        $nestedCode = $this->generateNestedRelationHandling($relatedModel, 'relationData', 4);

        return "
        // Handle {$relationName} relation update
        if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {
            foreach (\$validatedData['{$relationKey}'] as \$relationData) {
                if (isset(\$relationData['id'])) {
                    \$existing{$relationName} = \${$modelVariable}->{$relationName}()->find(\$relationData['id']);
                    if (\$existing{$relationName}) {
                        \$existing{$relationName}->update(\$relationData);
{$nestedCode}
                    }
                } else {
                    \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\$relationData);
{$nestedCode}
                }
            }
            unset(\$validatedData['{$relationKey}']);
        }";
    }

    /**
     * Generate hasOne update code
     */
    private function generateHasOneUpdateCode(string $relationName, string $relationKey, string $modelVariable, string $relatedModel): string
    {
        $nestedCode = $this->generateNestedRelationHandling($relatedModel, 'relationData', 4);

        return "
        // Handle {$relationName} relation update
        if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {
            \$relationData = \$validatedData['{$relationKey}'];
            \$related{$relationName} = \${$modelVariable}->{$relationName};

            if (\$related{$relationName}) {
                \$related{$relationName}->update(\$relationData);
{$nestedCode}
            } else {
                \$created{$relationName} = \${$modelVariable}->{$relationName}()->create(\$relationData);
{$nestedCode}
            }
            unset(\$validatedData['{$relationKey}']);
        }";
    }

    /**
     * Generate belongsToMany update code
     */
    private function generateBelongsToManyUpdateCode(string $relationName, string $relationKey, string $modelVariable): string
    {
        return "
        // Handle {$relationName} relation update (Many-to-Many)
        if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {
            \${$modelVariable}->{$relationName}()->sync(\$validatedData['{$relationKey}']);
            unset(\$validatedData['{$relationKey}']);
        }";
    }

    /**
     * Generate nested relation handling
     */
    private function generateNestedRelationHandling(string $modelName, string $dataVariable, int $indentLevel): string
    {
        if (!isset($this->allModels[$modelName]['relations'])) {
            return '';
        }

        $indent = str_repeat('    ', $indentLevel);
        $code = '';

        foreach ($this->allModels[$modelName]['relations'] as $relationName => $relationConfig) {
            if (!$this->shouldProcessRelation($relationConfig)) {
                continue;
            }

            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n{$indent}if (isset(\${$dataVariable}['{$relationKey}']) && is_array(\${$dataVariable}['{$relationKey}'])) {";
                    $code .= "\n{$indent}    \$created{$relationName}->{$relationName}()->createMany(\${$dataVariable}['{$relationKey}']);";
                    $code .= "\n{$indent}}";
                    break;
                case 'hasOne':
                    $code .= "\n{$indent}if (isset(\${$dataVariable}['{$relationKey}'])) {";
                    $code .= "\n{$indent}    \$created{$relationName}->{$relationName}()->create(\${$dataVariable}['{$relationKey}']);";
                    $code .= "\n{$indent}}";
                    break;
                case 'belongsToMany':
                    $code .= "\n{$indent}if (isset(\${$dataVariable}['{$relationKey}']) && is_array(\${$dataVariable}['{$relationKey}'])) {";
                    $code .= "\n{$indent}    \$created{$relationName}->{$relationName}()->sync(\${$dataVariable}['{$relationKey}']);";
                    $code .= "\n{$indent}}";
                    break;
            }
        }

        return $code;
    }

    /**
     * Generate relation imports
     */
    private function generateRelationImports(array $modelData): string
    {
        if (!isset($modelData['relations'])) {
            return '';
        }

        $imports = [];
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if ($this->shouldImportRelation($relationConfig)) {
                $relatedModel = $relationConfig['model'];
                $imports[] = "use App\\Models\\{$relatedModel};";

                // Recursively get nested imports
                $nestedImports = $this->getNestedRelationImports($relatedModel, []);
                $imports = array_merge($imports, $nestedImports);
            }
        }

        return empty($imports) ? '' : implode("\n", array_unique($imports));
    }

    /**
     * Get nested relation imports with cycle detection
     */
    private function getNestedRelationImports(string $modelName, array $visited = []): array
    {
        if (in_array($modelName, $visited) || !isset($this->allModels[$modelName]['relations'])) {
            return [];
        }

        $visited[] = $modelName;
        $imports = [];

        foreach ($this->allModels[$modelName]['relations'] as $relationName => $relationConfig) {
            if ($this->shouldImportRelation($relationConfig)) {
                $relatedModel = $relationConfig['model'];
                $imports[] = "use App\\Models\\{$relatedModel};";

                $nestedImports = $this->getNestedRelationImports($relatedModel, $visited);
                $imports = array_merge($imports, $nestedImports);
            }
        }

        return $imports;
    }

    /**
     * Get nested relation keys to exclude from main data
     */
    private function getNestedRelationKeys(string $modelName): string
    {
        if (!isset($this->allModels[$modelName]['relations'])) {
            return '';
        }

        $keys = [];
        foreach ($this->allModels[$modelName]['relations'] as $relationName => $relationConfig) {
            if ($this->shouldProcessRelation($relationConfig)) {
                $keys[] = "'" . Str::snake($relationName) . "'";
            }
        }

        return implode(', ', $keys);
    }

    /**
     * Helper methods for configuration checks
     */
    private function hasRelationsWithRequest(array $modelData): bool
    {
        if (!isset($modelData['relations'])) {
            return false;
        }

        foreach ($modelData['relations'] as $relationConfig) {
            if ($this->shouldProcessRelation($relationConfig)) {
                return true;
            }
        }

        return false;
    }

    private function hasApiResources(array $modelData): bool
    {
        return $modelData['generate']['resource'] ?? true;
    }

    private function hasMiddleware(array $modelData): bool
    {
        return isset($modelData['middleware']) || ($modelData['permissions'] ?? false);
    }

    private function shouldEagerLoad(array $relationConfig): bool
    {
        return $relationConfig['eagerLoad'] ??
            ($relationConfig['makeRequest'] ?? false) ||
            in_array($relationConfig['type'], ['belongsTo', 'hasOne']);
    }

    private function shouldProcessRelation(array $relationConfig): bool
    {
        return ($relationConfig['makeRequest'] ?? false) &&
            in_array($relationConfig['type'], ['hasMany', 'hasOne', 'belongsToMany']);
    }

    private function shouldImportRelation(array $relationConfig): bool
    {
        return $this->shouldProcessRelation($relationConfig) ||
            ($relationConfig['eagerLoad'] ?? false);
    }

    private function getServiceUsage(array $modelData): string
    {
        return ($modelData['generate']['service'] ?? true) ? 'service' : 'model';
    }

    private function getRepositoryUsage(array $modelData): string
    {
        return ($modelData['generate']['repository'] ?? false) ? 'repository' : 'model';
    }
}
