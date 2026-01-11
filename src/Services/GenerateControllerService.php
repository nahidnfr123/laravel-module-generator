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
        $hasService = $this->shouldGenerate('service', $modelData);
        $hasController = $this->shouldGenerate('controller', $modelData);
        $hasRelations = $this->hasNestedRequests($modelData);

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
     * Check if a component should be generated based on new YAML structure
     */
    private function shouldGenerate(string $component, array $modelData): bool
    {
        // Check generate field first
        if (isset($modelData['generate'])) {
            if ($modelData['generate'] === 'all') {
                return true;
            }
            if (is_array($modelData['generate'])) {
                return in_array($component, $modelData['generate']);
            }
        }

        // Check generate_except
        if (isset($modelData['generate_except'])) {
            $exceptions = is_array($modelData['generate_except'])
                ? $modelData['generate_except']
                : array_map('trim', explode(',', $modelData['generate_except']));

            return ! in_array($component, $exceptions);
        }

        // Default to true for all components
        return true;
    }

    /**
     * Check if model has nested_requests defined
     */
    private function hasNestedRequests(array $modelData): bool
    {
        return isset($modelData['nested_requests']) && ! empty($modelData['nested_requests']);
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
            $content = $this->generateControllerFromStub($modelConfig, $modelData, true);
        } else {
            $content = $this->generateLogicalController($modelConfig, $modelData);
        }

        File::put($path, $content);
        $this->command->info("🎮 Controller created: {$controllerClass}");
    }

    public function generateImageCode($modelConfig): array
    {
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

        $relationImports = $this->generateRelationImports($modelData, $modelConfig['studlyName']);
        $withRelations = $this->generateWithRelations($modelData);
        $relationStoreCode = $this->generateRelationStoreCode($modelData, $variable, $modelConfig['originalName']);
        $relationUpdateCode = $this->generateRelationUpdateCode($modelData, $variable, $modelConfig['originalName']);

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

        $relationImports = $this->generateRelationImports($modelData, $modelConfig['studlyName']);
        $withRelations = $this->generateWithRelations($modelData);
        $relationStoreCode = $this->generateRelationStoreCode($modelData, $variable, $modelConfig['originalName']);
        $relationUpdateCode = $this->generateRelationUpdateCode($modelData, $variable, $modelConfig['originalName']);

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
     * Generate relation imports
     */
    private function generateRelationImports(array $modelData, string $currentModelName = ''): string
    {
        if (! isset($modelData['nested_requests'])) {
            return '';
        }

        $nestedRequests = is_array($modelData['nested_requests'])
            ? $modelData['nested_requests']
            : array_map('trim', explode(',', $modelData['nested_requests']));

        $relations = $this->parseRelations($modelData['relations'] ?? []);
        $imports = [];

        foreach ($nestedRequests as $relationName) {
            $relationName = trim($relationName);
            if (isset($relations[$relationName])) {
                $relatedModel = $relations[$relationName]['model'];
                if ($relatedModel !== $currentModelName) {
                    $imports[] = "use App\\Models\\{$relatedModel};";
                }
            }
        }

        // Add DB and Storage imports if nested requests exist
        if (! empty($imports)) {
            array_unshift($imports, 'use Illuminate\\Support\\Facades\\DB;');

            // Check if any nested model has file/image fields
            $hasFileFields = false;
            foreach ($nestedRequests as $relationName) {
                $relationName = trim($relationName);
                if (isset($relations[$relationName])) {
                    $relatedModelData = $this->getRelatedModelData($relations[$relationName]['model']);
                    if ($this->hasFileOrImageFields($relatedModelData)) {
                        $hasFileFields = true;
                        break;
                    }
                }
            }

            if ($hasFileFields) {
                array_unshift($imports, 'use Illuminate\\Support\\Facades\\Storage;');
            }
        }

        return implode("\n", array_unique($imports));
    }

    /**
     * Generate with relations for eager loading
     */
    private function generateWithRelations(array $modelData): string
    {
        if (! isset($modelData['with'])) {
            return '';
        }

        $withList = is_array($modelData['with'])
            ? $modelData['with']
            : array_map('trim', explode(',', $modelData['with']));

        $relations = array_map(function ($rel) {
            return "'".trim($rel)."'";
        }, $withList);

        return implode(', ', $relations);
    }

    /**
     * Generate relation store code
     */
    private function generateRelationStoreCode(array $modelData, string $modelVariable, string $modelKey): string
    {
        if (! isset($modelData['nested_requests'])) {
            return '';
        }

        $nestedRequests = is_array($modelData['nested_requests'])
            ? $modelData['nested_requests']
            : array_map('trim', explode(',', $modelData['nested_requests']));

        $relations = $this->parseRelations($modelData['relations'] ?? []);
        $code = '';

        // Add DB transaction if nested requests exist
        $code .= "\n        DB::transaction(function () use (\$data, \${$modelVariable}) {";

        foreach ($nestedRequests as $relationName) {
            $relationName = trim($relationName);

            if (! isset($relations[$relationName])) {
                continue;
            }

            $relationConfig = $relations[$relationName];
            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            // Get related model data to check for file/image fields
            $relatedModelData = $this->getRelatedModelData($relationConfig['model']);
            $hasFileFields = $this->hasFileOrImageFields($relatedModelData);

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n            // Handle {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";

                    $nestedRelations = $this->getNestedRelations($relationConfig['model']);

                    if (! empty($nestedRelations) || $hasFileFields) {
                        $code .= "\n                \${$relationKey} = [];";
                        $code .= "\n                foreach (\$data['{$relationKey}'] as \$relationData) {";
                        $code .= "\n                    \${$relationKey}[] = \$relationData;";

                        // Add file upload logic if needed
                        if ($hasFileFields) {
                            $uploadCode = $this->generateNestedFileUploadCode($relatedModelData, $relationConfig['model']);
                            if ($uploadCode) {
                                $code .= $uploadCode;
                            }
                        }

                        foreach ($nestedRelations as $nestedRelationName => $nestedRelationConfig) {
                            $nestedRelationKey = Str::snake($nestedRelationName);
                            $nestedRelationType = $nestedRelationConfig['type'];

                            if ($nestedRelationType === 'hasMany') {
                                $code .= "\n                    // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                    if (!empty(\$relationData['{$nestedRelationKey}']) && is_array(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                        \${$relationName}Record->{$nestedRelationName}()->createMany(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                    }";
                            } elseif ($nestedRelationType === 'hasOne') {
                                $code .= "\n                    // Handle nested {$nestedRelationName} relation";
                                $code .= "\n                    if (!empty(\$relationData['{$nestedRelationKey}'])) {";
                                $code .= "\n                        \${$relationName}Record->{$nestedRelationName}()->create(\$relationData['{$nestedRelationKey}']);";
                                $code .= "\n                    }";
                            }
                        }

                        $code .= "\n                }";
                        $code .= "\n                    \${$modelVariable}->{$relationName}()->createMany(\${$relationKey});";
                    } else {
                        $code .= "\n                \${$modelVariable}->{$relationName}()->createMany(\$data['{$relationKey}']);";
                    }

                    $code .= "\n            }";
                    break;

                case 'hasOne':
                    $code .= "\n            // Handle {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";

                    // Add file upload logic if needed
                    if ($hasFileFields) {
                        $uploadCode = $this->generateNestedFileUploadCode($relatedModelData, $relationConfig['model'], true);
                        if ($uploadCode) {
                            $code .= $uploadCode;
                        }
                    }

                    $code .= "\n            \${$modelVariable}->{$relationName}()->create(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;

                case 'belongsToMany':
                    $code .= "\n            // Handle {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";
                    $code .= "\n                \${$modelVariable}->{$relationName}()->sync(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;
            }
        }

        $code .= "\n        });";

        return $code;
    }

    /**
     * Generate relation update code
     */
    /**
     * Generate relation update code
     */
    private function generateRelationUpdateCode(array $modelData, string $modelVariable, string $modelKey): string
    {
        if (! isset($modelData['nested_requests'])) {
            return '';
        }

        $nestedRequests = is_array($modelData['nested_requests'])
            ? $modelData['nested_requests']
            : array_map('trim', explode(',', $modelData['nested_requests']));

        $relations = $this->parseRelations($modelData['relations'] ?? []);
        $code = '';

        $code .= "\n        DB::transaction(function () use (\$data, \${$modelVariable}) {";

        foreach ($nestedRequests as $relationName) {
            $relationName = trim($relationName);

            if (! isset($relations[$relationName])) {
                continue;
            }

            $relationConfig = $relations[$relationName];
            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            $relatedModelData = $this->getRelatedModelData($relationConfig['model']);
            $hasFileFields = $this->hasFileOrImageFields($relatedModelData);
            $nestedRelations = $this->getNestedRelations($relationConfig['model']);

            switch ($relationType) {

                /**
                 * =========================
                 * HAS MANY UPDATE
                 * =========================
                 */
                case 'hasMany':
                    $code .= "\n            // Update {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";
                    $code .= "\n                \$existingIds = \${$modelVariable}->{$relationName}()->pluck('id')->toArray();";
                    $code .= "\n                \$keptIds = [];";

                    $code .= "\n                foreach (\$data['{$relationKey}'] as \$relationData) {";

                    if ($hasFileFields) {
                        $uploadCode = $this->generateNestedFileUploadCode(
                            $relatedModelData,
                            $relationConfig['model']
                        );
                        if ($uploadCode) {
                            $code .= $uploadCode;
                        }
                    }

                    $code .= "\n                    \$record = \${$modelVariable}->{$relationName}()->updateOrCreate(";
                    $code .= "\n                        ['id' => \$relationData['id'] ?? null],";
                    $code .= "\n                        \$relationData";
                    $code .= "\n                    );";

                    $code .= "\n                    \$keptIds[] = \$record->id;";

                    /**
                     * Nested relations
                     */
                    foreach ($nestedRelations as $nestedRelationName => $nestedRelationConfig) {
                        $nestedKey = Str::snake($nestedRelationName);
                        $nestedType = $nestedRelationConfig['type'];

                        if ($nestedType === 'hasMany') {
                            $code .= "\n                    if (!empty(\$relationData['{$nestedKey}'])) {";
                            $code .= "\n                        \$record->{$nestedRelationName}()->delete();";
                            $code .= "\n                        \$record->{$nestedRelationName}()->createMany(\$relationData['{$nestedKey}']);";
                            $code .= "\n                    }";
                        }

                        if ($nestedType === 'hasOne') {
                            $code .= "\n                    if (!empty(\$relationData['{$nestedKey}'])) {";
                            $code .= "\n                        \$record->{$nestedRelationName}()->updateOrCreate([], \$relationData['{$nestedKey}']);";
                            $code .= "\n                    }";
                        }
                    }

                    $code .= "\n                }";

                    /**
                     * Delete removed records
                     */
                    $code .= "\n                \${$modelVariable}->{$relationName}()->whereNotIn('id', \$keptIds)->delete();";

                    $code .= "\n                unset(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;

                    /**
                     * =========================
                     * HAS ONE UPDATE
                     * =========================
                     */
                case 'hasOne':
                    $code .= "\n            // Update {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";

                    if ($hasFileFields) {
                        $deleteCode = $this->generateNestedFileDeleteCode(
                            $relatedModelData,
                            $relationName,
                            $modelVariable,
                            true
                        );
                        if ($deleteCode) {
                            $code .= $deleteCode;
                        }

                        $uploadCode = $this->generateNestedFileUploadCode(
                            $relatedModelData,
                            $relationConfig['model'],
                            true
                        );
                        if ($uploadCode) {
                            $code .= $uploadCode;
                        }
                    }

                    $code .= "\n                \${$modelVariable}->{$relationName}()->updateOrCreate([], \$data['{$relationKey}']);";
                    $code .= "\n                unset(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;

                    /**
                     * =========================
                     * BELONGS TO MANY UPDATE
                     * =========================
                     */
                case 'belongsToMany':
                    $code .= "\n            // Update {$relationName} relation";
                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";
                    $code .= "\n                \${$modelVariable}->{$relationName}()->sync(\$data['{$relationKey}']);";
                    $code .= "\n                unset(\$data['{$relationKey}']);";
                    $code .= "\n            }";
                    break;
            }
        }

        $code .= "\n        });";

        return $code;
    }

    /**/
    //    private function generateRelationUpdateCode(array $modelData, string $modelVariable, string $modelKey): string
    //    {
    //        if (! isset($modelData['nested_requests'])) {
    //            return '';
    //        }
    //
    //        $nestedRequests = is_array($modelData['nested_requests'])
    //            ? $modelData['nested_requests']
    //            : array_map('trim', explode(',', $modelData['nested_requests']));
    //
    //        $relations = $this->parseRelations($modelData['relations'] ?? []);
    //        $code = '';
    //
    //        $code .= "\n        DB::transaction(function () use (\$data, \${$modelVariable}) {";
    //
    //        foreach ($nestedRequests as $relationName) {
    //            $relationName = trim($relationName);
    //
    //            if (! isset($relations[$relationName])) {
    //                continue;
    //            }
    //
    //            $relationConfig = $relations[$relationName];
    //            $relationKey = Str::snake($relationName);
    //            $relationType = $relationConfig['type'];
    //
    //            $relatedModelData = $this->getRelatedModelData($relationConfig['model']);
    //            $hasFileFields = $this->hasFileOrImageFields($relatedModelData);
    //            $nestedRelations = $this->getNestedRelations($relationConfig['model']);
    //
    //            switch ($relationType) {
    //
    //                /**
    //                 * ==================================================
    //                 * HAS MANY — UPSERT + NESTED SYNC
    //                 * ==================================================
    //                 */
    //                case 'hasMany':
    //                    $code .= "\n            // Update {$relationName} relation (UPSERT)";
    //                    $code .= "\n            if (!empty(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";
    //
    //                    $code .= "\n                \$rows = [];";
    //                    $code .= "\n                \$ids = [];";
    //                    $code .= "\n                \$nested = [];";
    //
    //                    $code .= "\n                foreach (\$data['{$relationKey}'] as \$item) {";
    //
    //                    if ($hasFileFields) {
    //                        $uploadCode = $this->generateNestedFileUploadCode(
    //                            $relatedModelData,
    //                            $relationConfig['model']
    //                        );
    //                        if ($uploadCode) {
    //                            $code .= $uploadCode;
    //                        }
    //                    }
    //
    //                    // Extract nested relations
    //                    foreach ($nestedRelations as $nestedName => $nestedConfig) {
    //                        $nestedKey = Str::snake($nestedName);
    //                        $code .= "\n                    \$nested[\$item['id'] ?? null]['{$nestedKey}'] = \$item['{$nestedKey}'] ?? null;";
    //                        $code .= "\n                    unset(\$item['{$nestedKey}']);";
    //                    }
    //
    //                    // Attach foreign key
    //                    $code .= "\n                    \$item['{$modelKey}'] = \${$modelVariable}->id;";
    //                    $code .= "\n                    \$rows[] = \$item;";
    //
    //                    $code .= "\n                    if (!empty(\$item['id'])) {";
    //                    $code .= "\n                        \$ids[] = \$item['id'];";
    //                    $code .= "\n                    }";
    //
    //                    $code .= "\n                }";
    //
    //                    // UPSERT
    //                    $code .= "\n                \${$modelVariable}->{$relationName}()->getModel()::upsert(";
    //                    $code .= "\n                    \$rows,";
    //                    $code .= "\n                    ['id'],";
    //                    $code .= "\n                    array_keys(\$rows[0] ?? [])";
    //                    $code .= "\n                );";
    //
    //                    // Fetch affected records
    //                    $code .= "\n                \$records = \${$modelVariable}->{$relationName}()";
    //                    $code .= "\n                    ->whereIn('id', \$ids)";
    //                    $code .= "\n                    ->get();";
    //
    //                    // Sync nested relations
    //                    foreach ($nestedRelations as $nestedName => $nestedConfig) {
    //                        $nestedKey = Str::snake($nestedName);
    //                        $nestedType = $nestedConfig['type'];
    //
    //                        if ($nestedType === 'hasMany') {
    //                            $code .= "\n                foreach (\$records as \$record) {";
    //                            $code .= "\n                    if (!empty(\$nested[\$record->id]['{$nestedKey}'])) {";
    //                            $code .= "\n                        \$record->{$nestedName}()->delete();";
    //                            $code .= "\n                        \$record->{$nestedName}()->createMany(";
    //                            $code .= "\n                            \$nested[\$record->id]['{$nestedKey}']";
    //                            $code .= "\n                        );";
    //                            $code .= "\n                    }";
    //                            $code .= "\n                }";
    //                        }
    //
    //                        if ($nestedType === 'hasOne') {
    //                            $code .= "\n                foreach (\$records as \$record) {";
    //                            $code .= "\n                    if (!empty(\$nested[\$record->id]['{$nestedKey}'])) {";
    //                            $code .= "\n                        \$record->{$nestedName}()";
    //                            $code .= "\n                            ->updateOrCreate([], \$nested[\$record->id]['{$nestedKey}']);";
    //                            $code .= "\n                    }";
    //                            $code .= "\n                }";
    //                        }
    //                    }
    //
    //                    // Delete removed rows
    //                    $code .= "\n                \${$modelVariable}->{$relationName}()";
    //                    $code .= "\n                    ->whereNotIn('id', \$ids)";
    //                    $code .= "\n                    ->delete();";
    //
    //                    $code .= "\n                unset(\$data['{$relationKey}']);";
    //                    $code .= "\n            }";
    //                    break;
    //
    //                /**
    //                 * ==================================================
    //                 * HAS ONE
    //                 * ==================================================
    //                 */
    //                case 'hasOne':
    //                    $code .= "\n            // Update {$relationName} relation";
    //                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";
    //
    //                    if ($hasFileFields) {
    //                        $deleteCode = $this->generateNestedFileDeleteCode(
    //                            $relatedModelData,
    //                            $relationName,
    //                            $modelVariable,
    //                            true
    //                        );
    //                        if ($deleteCode) {
    //                            $code .= $deleteCode;
    //                        }
    //
    //                        $uploadCode = $this->generateNestedFileUploadCode(
    //                            $relatedModelData,
    //                            $relationConfig['model'],
    //                            true
    //                        );
    //                        if ($uploadCode) {
    //                            $code .= $uploadCode;
    //                        }
    //                    }
    //
    //                    $code .= "\n                \${$modelVariable}->{$relationName}()";
    //                    $code .= "\n                    ->updateOrCreate([], \$data['{$relationKey}']);";
    //
    //                    $code .= "\n                unset(\$data['{$relationKey}']);";
    //                    $code .= "\n            }";
    //                    break;
    //
    //                /**
    //                 * ==================================================
    //                 * BELONGS TO MANY
    //                 * ==================================================
    //                 */
    //                case 'belongsToMany':
    //                    $code .= "\n            // Update {$relationName} relation";
    //                    $code .= "\n            if (!empty(\$data['{$relationKey}'])) {";
    //                    $code .= "\n                \${$modelVariable}->{$relationName}()->sync(\$data['{$relationKey}']);";
    //                    $code .= "\n                unset(\$data['{$relationKey}']);";
    //                    $code .= "\n            }";
    //                    break;
    //            }
    //        }
    //
    //        $code .= "\n        });";
    //
    //        return $code;
    //    }

    /**
     * Get nested relations for a given model
     */
    private function getNestedRelations(string $relatedModelName): array
    {
        $nestedRelations = [];

        // Find the related model in allModels
        foreach ($this->allModels as $modelKey => $modelData) {
            if (Str::studly($modelKey) === $relatedModelName) {
                if (! isset($modelData['nested_requests'])) {
                    break;
                }

                $nestedRequests = is_array($modelData['nested_requests'])
                    ? $modelData['nested_requests']
                    : array_map('trim', explode(',', $modelData['nested_requests']));

                $relations = $this->parseRelations($modelData['relations'] ?? []);

                foreach ($nestedRequests as $relationName) {
                    $relationName = trim($relationName);
                    if (isset($relations[$relationName])) {
                        $nestedRelations[$relationName] = $relations[$relationName];
                    }
                }
                break;
            }
        }

        return $nestedRelations;
    }

    /**
     * Get related model data
     */
    private function getRelatedModelData(string $relatedModelName): ?array
    {
        foreach ($this->allModels as $modelKey => $modelData) {
            if (Str::studly($modelKey) === $relatedModelName) {
                return $modelData;
            }
        }

        return null;
    }

    /**
     * Check if model has file or image fields
     */
    private function hasFileOrImageFields(?array $modelData): bool
    {
        if (! $modelData || ! isset($modelData['fields'])) {
            return false;
        }

        foreach ($modelData['fields'] as $fieldName => $fieldDefinition) {
            $type = explode(':', $fieldDefinition)[0];
            if (in_array($type, ['file', 'image'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate file upload code for nested relations
     */
    private function generateNestedFileUploadCode(?array $modelData, string $modelName, bool $isSingle = false): string
    {
        if (! $modelData || ! isset($modelData['fields'])) {
            return '';
        }

        $code = '';
        $varName = $isSingle ? 'data' : 'relationData';
        $camelModelName = Str::camel($modelName);

        foreach ($modelData['fields'] as $fieldName => $fieldDefinition) {
            $type = explode(':', $fieldDefinition)[0];

            if ($type === 'image' || $type === 'file') {
                $code .= "\n                    if (!empty(\${$varName}['{$fieldName}']) && \${$varName}['{$fieldName}'] instanceof \\Illuminate\\Http\\UploadedFile) {";
                $code .= "\n                        \${$varName}['{$fieldName}'] = uploadFile(\${$varName}['{$fieldName}'], '{$camelModelName}', 'public');";
                $code .= "\n                    }";
            }
        }

        return $code;
    }

    /**
     * Generate file delete code for nested relations
     */
    private function generateNestedFileDeleteCode(?array $modelData, string $relationName, string $modelVariable, bool $isSingle = false): string
    {
        if (! $modelData || ! isset($modelData['fields'])) {
            return '';
        }

        $code = '';
        $fileFields = [];

        foreach ($modelData['fields'] as $fieldName => $fieldDefinition) {
            $type = explode(':', $fieldDefinition)[0];
            if ($type === 'image' || $type === 'file') {
                $fileFields[] = $fieldName;
            }
        }

        if (empty($fileFields)) {
            return '';
        }

        if ($isSingle) {
            $code .= "\n                if (\${$modelVariable}->{$relationName}) {";
            foreach ($fileFields as $field) {
                $code .= "\n                    if (\${$modelVariable}->{$relationName}->{$field} && Storage::disk('public')->exists(\${$modelVariable}->{$relationName}->{$field})) {";
                $code .= "\n                        Storage::disk('public')->delete(\${$modelVariable}->{$relationName}->{$field});";
                $code .= "\n                    }";
            }
            $code .= "\n                }";
        } else {
            $code .= "\n                foreach (\${$modelVariable}->{$relationName} as \$record) {";
            foreach ($fileFields as $field) {
                $code .= "\n                    if (\$record->{$field} && Storage::disk('public')->exists(\$record->{$field})) {";
                $code .= "\n                        Storage::disk('public')->delete(\$record->{$field});";
                $code .= "\n                    }";
            }
            $code .= "\n                }";
        }

        return $code;
    }

    private function buildModelConfiguration(string $modelName, array $modelData): array
    {
        $studlyModelName = Str::studly($modelName);

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
