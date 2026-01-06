<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;
use NahidFerdous\LaravelModuleGenerator\Services\Extra\FileUploadCodeGenerator;

class GenerateControllerService
{
    private const DEFAULT_GENERATE_CONFIG = [
        'model' => true,
        'migration' => true,
        'controller' => true,
        'service' => true,
        'request' => true,
        'resource' => true,
        'collection' => true,
    ];

    private GenerateModuleFromYaml $command;

    private array $allModels;

    private StubPathResolverService $stubPathResolver;

    public function __construct(GenerateModuleFromYaml $command, array $allModels)
    {
        $this->command = $command;
        $this->allModels = $allModels;
        $this->stubPathResolver = new StubPathResolverService;
    }

    /**
     * Generate controller and service based on model configuration
     */
    public function generateControllerAndService(array $modelConfig, array $modelData, bool $force = false): void
    {
        $generate = $modelData['generate'] ?? [];
        $hasService = $generate['service'] ?? true;
        $hasController = $generate['controller'] ?? true;
        $hasRelations = $this->hasRelationRequest($modelData);

        if ($hasService) {
            $servicePath = app_path("Services/{$modelConfig['classes']['service']}.php");

            if (File::exists($servicePath) && ! $force) {
                $this->command->warn("⚠️ Service already exists: {$modelConfig['classes']['service']}");

                return;
            }

            if (File::exists($servicePath)) {
                File::delete($servicePath);
                $this->command->warn("⚠️ Deleted existing service: {$modelConfig['classes']['service']}");
            }
            $this->generateService($modelConfig, $modelData, $hasRelations);
        }

        if ($hasController) {
            $controllerPath = app_path("Http/Controllers/{$modelConfig['classes']['controller']}.php");

            if (File::exists($controllerPath) && ! $force) {
                $this->command->warn("⚠️ Controller already exists: {$modelConfig['classes']['controller']}");

                return;
            }

            if (File::exists($controllerPath)) {
                File::delete($controllerPath);
                $this->command->warn("⚠️ Deleted existing controller: {$modelConfig['classes']['controller']}");
            }
            $this->generateController($modelConfig, $modelData, $hasService, $hasRelations);
        }
    }

    /**
     * Check if model has relations with makeRequest = true
     */
    private function hasRelationRequest(array $modelData): bool
    {
        if (! isset($modelData['relations']) || ! is_array($modelData['relations'])) {
            return false;
        }

        foreach ($modelData['relations'] as $relation) {
            if (isset($relation['makeRequest']) && $relation['makeRequest'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate service file
     */
    private function generateService(array $modelConfig, array $modelData, bool $hasRelations): void
    {
        $serviceClass = $modelConfig['classes']['service'];
        $serviceDir = app_path('Services');
        $path = "{$serviceDir}/{$serviceClass}.php";

        if (File::exists($path)) {
            $this->command->warn("Service {$serviceClass} already exists. Skipping generation.");

            return;
        }

        File::ensureDirectoryExists($serviceDir);
        $content = $this->generateServiceFromStub($modelConfig, $modelData);

        File::put($path, $content);
        $this->command->info("🔧 Service created: {$serviceClass}");
    }

    /**
     * Generate controller file
     */
    private function generateController(array $modelConfig, array $modelData, bool $hasService, bool $hasRelations): void
    {
        $controllerClass = $modelConfig['classes']['controller'];
        $path = app_path("Http/Controllers/{$controllerClass}.php");

        if (File::exists($path)) {
            $this->command->warn("Controller {$controllerClass} already exists. Skipping generation.");

            return;
        }

        File::ensureDirectoryExists(app_path('Http/Controllers'));

        if ($hasService) {
            // Generate controller from default stub with service
            $content = $this->generateControllerFromStub($modelConfig, $modelData, true);
        } else {
            // Generate controller without service (custom code)
            $content = $this->generateLogicalController($modelConfig, $modelData);
        }

        File::put($path, $content);
        $this->command->info("🎮 Controller created: {$controllerClass}");
    }

    public function generateImageCode($modelConfig): array
    {
        // Generate file upload logic
        $uploadLogicStore = FileUploadCodeGenerator::generateStoreUploadLogic(
            $modelConfig['fields'],
            $modelConfig['studlyName'],
            $modelConfig['camelName']
        );

        $uploadLogicUpdate = FileUploadCodeGenerator::generateUpdateUploadLogic(
            $modelConfig['fields'],
            $modelConfig['studlyName'],
            $modelConfig['camelName']
        );

        $deleteFileLogic = FileUploadCodeGenerator::generateDeleteFileLogic(
            $modelConfig['fields'],
            $modelConfig['camelName']
        );

        return [
            '{{ uploadLogicStore }}' => $uploadLogicStore,
            '{{ uploadLogicUpdate }}' => $uploadLogicUpdate,
            '{{ deleteFileLogic }}' => $deleteFileLogic,
        ];
    }

    /**
     * Generate service from default stub
     */
    private function generateServiceFromStub(array $modelConfig, array $modelData): string
    {
        $stubPath = $this->stubPathResolver->resolveStubPath('service');
        $stubContent = File::get($stubPath);

        $variable = $modelConfig['camelName'];

        $relationImports = $this->generateRelationImports($modelData, $modelConfig['originalName'] ?? $modelConfig['studlyName'] ?? '');
        $withRelations = $this->generateWithRelations($modelData);
        $relationStoreCode = $this->generateRelationStoreCode($modelData, $variable);
        $relationUpdateCode = $this->generateRelationUpdateCode($modelData, $variable);

        $replacements = [
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ relationImports }}' => $relationImports,
            '{{ with }}' => $withRelations ? "with([{$withRelations}])->" : '',
            '{{ repositoryUsage }}' => 'model',
            ...$this->generateImageCode($modelConfig),
            '{{ relationalQueryStore }}' => $relationStoreCode,
            '{{ relationalQueryUpdate }}' => $relationUpdateCode,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Generate controller from default stub
     */
    private function generateControllerFromStub(array $modelConfig): string
    {
        $stubPath = $this->stubPathResolver->resolveStubPath('controller');
        $stubContent = File::get($stubPath);

        $replacements = [
            '{{ class }}' => $modelConfig['classes']['controller'],
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ modelPlural }}' => $modelConfig['pluralStudlyName'],
            '{{ route }}' => $modelConfig['tableName'],
            ...$this->generateImageCode($modelConfig),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Generate controller without service (custom code)
     */
    private function generateLogicalController(array $modelConfig, array $modelData): string
    {
        $stubPath = $this->stubPathResolver->resolveStubPath('controller-logical');
        $stubContent = File::get($stubPath);

        $variable = $modelConfig['camelName'];

        $relationImports = $this->generateRelationImports($modelData, $modelConfig['originalName'] ?? $modelConfig['studlyName'] ?? '');
        $withRelations = $this->generateWithRelations($modelData);
        $relationStoreCode = $this->generateRelationStoreCode($modelData, $variable);
        $relationUpdateCode = $this->generateRelationUpdateCode($modelData, $variable);

        $replacements = [
            '{{ class }}' => $modelConfig['classes']['controller'],
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ modelPlural }}' => $modelConfig['pluralStudlyName'],
            '{{ route }}' => $modelConfig['tableName'],
            ...$this->generateImageCode($modelConfig),
            '{{ relationImports }}' => $relationImports,
            '{{ with }}' => $withRelations ? "with([{$withRelations}])->" : '',
            '{{ repositoryUsage }}' => 'model',
            '{{ relationalQueryStore }}' => $relationStoreCode,
            '{{ relationalQueryUpdate }}' => $relationUpdateCode,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Generate relation imports
     */
    private function generateRelationImports(array $modelData, $currentModelName = ''): string
    {
        if (! isset($modelData['relations'])) {
            return '';
        }

        $imports = [];
        foreach ($modelData['relations'] as $relationConfig) {
            if (isset($relationConfig['model']) && $currentModelName !== $relationConfig['model']) {
                if (isset($relationConfig['makeRequest']) && $relationConfig['makeRequest'] === true) {
                    $relatedModel = $relationConfig['model'];
                    $imports[] = "use App\\Models\\{$relatedModel};";
                }
            }
        }

        return implode("\n", array_unique($imports));
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
            if (isset($relationConfig['with']) && $relationConfig['with'] == true) {
                $relations[] = "'{$relationName}'";
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

            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n        // Handle {$relationName} relation";
                    $code .= "\n        if (isset(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";

                    // Check if this relation has nested relations
                    $nestedRelations = $this->getNestedRelations($relationConfig, $modelData);

                    if (! empty($nestedRelations)) {
                        $code .= "\n            foreach (\$data['{$relationKey}'] as \$relationData) {";
                        $code .= "\n                \${$relationName}Record = \${$modelVariable}->{$relationName}()->create(\$relationData);";

                        foreach ($nestedRelations as $nestedRelationName => $nestedRelationConfig) {
                            $nestedRelationKey = Str::snake($nestedRelationName);
                            $nestedRelationType = $nestedRelationConfig['type'];

                            if ($nestedRelationType === 'hasMany') {
                                $code .= "\n                // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                if (isset(\$relationData['{$nestedRelationKey}']) && is_array(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                    \${$relationName}Record->{$nestedRelationName}()->createMany(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                }";
                            } elseif ($nestedRelationType === 'hasOne') {
                                $code .= "\n                // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                if (isset(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                    \${$relationName}Record->{$nestedRelationName}()->create(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                }";
                            }
                        }

                        $code .= "\n            }";
                    } else {
                        $code .= "\n            \${$modelVariable}->{$relationName}()->createMany(\$data['{$relationKey}']);";
                    }

                    $code .= "\n        }";
                    break;

                case 'hasOne':
                    $code .= "\n        // Handle {$relationName} relation";
                    $code .= "\n        if (isset(\$data['{$relationKey}'])) {";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->create(\$data['{$relationKey}']);";
                    $code .= "\n        }";
                    break;

                case 'belongsToMany':
                    $code .= "\n        // Handle {$relationName} relation";
                    $code .= "\n        if (isset(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->sync(\$data['{$relationKey}']);";
                    $code .= "\n        }";
                    break;
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

            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n        // Handle {$relationName} relation update";
                    $code .= "\n        if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {";

                    // Check if this relation has nested relations
                    $nestedRelations = $this->getNestedRelations($relationConfig, $modelData);

                    if (! empty($nestedRelations)) {
                        // For nested relations, we need to handle them more carefully
                        $code .= "\n            // Delete existing {$relationName} and their nested relations";
                        $code .= "\n            \${$modelVariable}->{$relationName}()->each(function (\$record) {";

                        foreach ($nestedRelations as $nestedRelationName => $nestedRelationConfig) {
                            $code .= "\n                \$record->{$nestedRelationName}()->delete();";
                        }

                        $code .= "\n            });";
                        $code .= "\n            \${$modelVariable}->{$relationName}()->delete();";
                        $code .= "\n";
                        $code .= "\n            // Create new {$relationName} with nested relations";
                        $code .= "\n            foreach (\$validatedData['{$relationKey}'] as \$relationData) {";
                        $code .= "\n                \${$relationName}Record = \${$modelVariable}->{$relationName}()->create(\$relationData);";

                        foreach ($nestedRelations as $nestedRelationName => $nestedRelationConfig) {
                            $nestedRelationKey = Str::snake($nestedRelationName);
                            $nestedRelationType = $nestedRelationConfig['type'];

                            if ($nestedRelationType === 'hasMany') {
                                $code .= "\n                // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                if (isset(\$relationData['{$nestedRelationKey}']) && is_array(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                    \${$relationName}Record->{$nestedRelationName}()->createMany(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                }";
                            } elseif ($nestedRelationType === 'hasOne') {
                                $code .= "\n                // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                if (isset(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                    \${$relationName}Record->{$nestedRelationName}()->create(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                }";
                            }
                        }

                        $code .= "\n            }";
                    } else {
                        $code .= "\n            \${$modelVariable}->{$relationName}()->delete();";
                        $code .= "\n            \${$modelVariable}->{$relationName}()->createMany(\$validatedData['{$relationKey}']);";
                    }

                    $code .= "\n            unset(\$validatedData['{$relationKey}']);";
                    $code .= "\n        }";
                    break;

                case 'hasOne':
                    $code .= "\n        // Handle {$relationName} relation update";
                    $code .= "\n        if (isset(\$validatedData['{$relationKey}'])) {";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->delete();";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->create(\$validatedData['{$relationKey}']);";
                    $code .= "\n            unset(\$validatedData['{$relationKey}']);";
                    $code .= "\n        }";
                    break;

                case 'belongsToMany':
                    $code .= "\n        // Handle {$relationName} relation update";
                    $code .= "\n        if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->sync(\$validatedData['{$relationKey}']);";
                    $code .= "\n            unset(\$validatedData['{$relationKey}']);";
                    $code .= "\n        }";
                    break;
            }
        }

        return $code;
    }

    /**
     * Get nested relations for a given relation
     */
    private function getNestedRelations(array $relationConfig, array $modelData): array
    {
        $nestedRelations = [];

        if (! isset($relationConfig['model'])) {
            return $nestedRelations;
        }

        $relatedModelName = $relationConfig['model'];

        // Find the related model in allModels to get its relations
        foreach ($this->allModels as $modelName => $modelD) {
            $modelConfig = $this->buildModelConfiguration($modelName, $modelD);
            if ($modelConfig['studlyName'] === $relatedModelName) {
                if (isset($modelConfig['relations'])) {
                    foreach ($modelConfig['relations'] as $nestedRelationName => $nestedRelationConfig) {
                        if (isset($nestedRelationConfig['makeRequest']) && $nestedRelationConfig['makeRequest'] === true) {
                            $nestedRelations[$nestedRelationName] = $nestedRelationConfig;
                        }
                    }
                }
                break;
            }
        }

        return $nestedRelations;
    }

    private function buildModelConfiguration(string $modelName, array $modelData): array
    {
        $studlyModelName = Str::studly($modelName);

        // Validate generate configuration
        if (isset($modelData['generate']) && is_array($modelData['generate'])) {
            $unknownKeys = array_diff(array_keys($modelData['generate']), array_keys(self::DEFAULT_GENERATE_CONFIG));
            if (! empty($unknownKeys)) {
                throw new \InvalidArgumentException("Unknown generate keys for $modelName: ".implode(', ', $unknownKeys));
            }
        }

        return [
            'originalName' => $modelName,
            'studlyName' => $studlyModelName,
            'camelName' => Str::camel($studlyModelName),
            'pluralStudlyName' => Str::pluralStudly($studlyModelName),
            'tableName' => Str::snake(Str::plural($studlyModelName)),
            'fields' => $modelData['fields'] ?? [],
            'relations' => $modelData['relations'] ?? [],
            'classes' => [
                'controller' => "{$studlyModelName}Controller",
                'service' => "{$studlyModelName}Service",
                'collection' => "{$studlyModelName}Collection",
                'resource' => "{$studlyModelName}Resource",
                'request' => "{$studlyModelName}Request",
            ],
        ];
    }
}
