<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

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

        if ($hasRelations) {
            // Generate service with relations (custom code)
            $content = $this->generateServiceWithRelations($modelConfig, $modelData);
        } else {
            // Generate service from default stub
            $content = $this->generateServiceFromStub($modelConfig, $modelData);
        }

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

        if ($hasRelations) {
            // Generate controller with relations (custom code)
            $content = $this->generateControllerWithRelations($modelConfig, $modelData, $hasService);
        } elseif ($hasService) {
            // Generate controller from default stub with service
            $content = $this->generateControllerFromStub($modelConfig, $modelData, true);
        } else {
            // Generate controller without service (custom code)
            $content = $this->generateControllerWithoutService($modelConfig, $modelData);
        }

        File::put($path, $content);
        $this->command->info("🎮 Controller created: {$controllerClass}");
    }

    /**
     * Generate service from default stub
     */
    private function generateServiceFromStub(array $modelConfig, array $modelData): string
    {
        $stubPath = $this->stubPathResolver->resolveStubPath('service');
        $stubContent = File::get($stubPath);

        $replacements = [
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ relationImports }}' => '',
            '{{ with }}' => '',
            '{{ repositoryUsage }}' => 'model',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Generate service with relations (custom code)
     */
    private function generateServiceWithRelations(array $modelConfig, array $modelData): string
    {
        $modelName = $modelConfig['studlyName'];
        $variable = $modelConfig['camelName'];

        $relationImports = $this->generateRelationImports($modelData);
        $withRelations = $this->generateWithRelations($modelData);
        $relationStoreCode = $this->generateRelationStoreCode($modelData, $variable);
        $relationUpdateCode = $this->generateRelationUpdateCode($modelData, $variable);

        $getAllQuery = "{$modelName}::paginate()";
        $getByIdQuery = "{$modelName}::findOrFail(\$id)";
        if ($withRelations) {
            $getAllQuery = "{$modelName}::with([{$withRelations}])->paginate()";
            $getByIdQuery = "{$modelName}::with([{$withRelations}])->findOrFail(\$id)";
        }

        return "<?php

namespace App\Services;

use App\Models\\{$modelName};
{$relationImports}

class {$modelName}Service
{
    /**
     * Get all {$modelName} records
     */
    public function getAll()
    {
        return {$getAllQuery};
    }

    /**
     * Get {$modelName} by ID
     */
    public function getById(\$id)
    {
        return {$getByIdQuery};
    }

    /**
     * Create a new {$modelName}
     */
    public function store(array \$data)
    {
        \${$variable} = {$modelName}::create(\$data);
{$relationStoreCode}

        return \${$variable};
    }

    /**
     * Update {$modelName}
     */
    public function update(\${$variable}, array \$data)
    {
        // Handle nested relations update
        \$validatedData = \$data;
{$relationUpdateCode}

        \${$variable}->update(\$validatedData);

        return \${$variable};
    }

    /**
     * Delete {$modelName}
     */
    public function delete(\${$variable})
    {
        return \${$variable}->delete();
    }
}";
    }

    /**
     * Generate controller from default stub
     */
    private function generateControllerFromStub(array $modelConfig, array $modelData, bool $withService): string
    {
        $stubPath = $this->stubPathResolver->resolveStubPath('controller');
        $stubContent = File::get($stubPath);

        $replacements = [
            '{{ class }}' => $modelConfig['classes']['controller'],
            '{{ model }}' => $modelConfig['studlyName'],
            '{{ variable }}' => $modelConfig['camelName'],
            '{{ modelPlural }}' => $modelConfig['pluralStudlyName'],
            '{{ route }}' => $modelConfig['tableName'],
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stubContent);
    }

    /**
     * Generate controller without service (custom code)
     */
    private function generateControllerWithoutService(array $modelConfig, array $modelData): string
    {
        $modelName = $modelConfig['studlyName'];
        $variable = $modelConfig['camelName'];
        $controllerClass = $modelConfig['classes']['controller'];
        $tableName = $modelConfig['tableName'];
        $modelPlural = $modelConfig['pluralStudlyName'];

        return "<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\\{$modelName}Request;
use App\Http\Resources\\{$modelName}\\{$modelName}Collection;
use App\Http\Resources\\{$modelName}\\{$modelName}Resource;
use App\Models\\{$modelName};
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class {$controllerClass} extends Controller implements HasMiddleware
{
    use ApiResponseTrait;

    public static function middleware(): array
    {
        \$model = '{$tableName}';

        return [
            'auth',
            new Middleware([\"permission:view_\$model\"], only: ['index']),
            new Middleware([\"permission:show_\$model\"], only: ['show']),
            new Middleware([\"permission:create_\$model\"], only: ['store']),
            new Middleware([\"permission:update_\$model\"], only: ['update']),
            new Middleware([\"permission:delete_\$model\"], only: ['destroy']),
        ];
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        \$data = {$modelName}::paginate();

        return \$this->success('{$modelPlural} retrieved successfully', {$modelName}Collection::make(\$data));
    }

    public function store({$modelName}Request \$request): \Illuminate\Http\JsonResponse
    {
        try {
            \${$variable} = {$modelName}::create(\$request->validated());

            return \$this->success('{$modelName} created successfully', new {$modelName}Resource(\${$variable}));
        } catch (\Exception \$e) {
            return \$this->failure('{$modelName} creation failed', 500, \$e->getMessage());
        }
    }

    public function show({$modelName} \${$variable}): \Illuminate\Http\JsonResponse
    {
        return \$this->success('{$modelName} retrieved successfully', new {$modelName}Resource(\${$variable}));
    }

    public function update({$modelName}Request \$request, {$modelName} \${$variable}): \Illuminate\Http\JsonResponse
    {
        try {
            \${$variable}->update(\$request->validated());

            return \$this->success('{$modelName} updated successfully', new {$modelName}Resource(\${$variable}));
        } catch (\Exception \$e) {
            return \$this->failure('{$modelName} update failed', 500, \$e->getMessage());
        }
    }

     public function destroy({$modelName} \${$variable}): \Illuminate\Http\JsonResponse
    {
        try {
             \${$variable}->delete();

            return \$this->success('{$modelName} deleted successfully');
        } catch (\Exception \$e) {
            return \$this->failure('{$modelName} deletion failed', 500, \$e->getMessage());
        }
    }
}";
    }

    /**
     * Generate controller with relations (custom code)
     */
    private function generateControllerWithRelations(array $modelConfig, array $modelData, bool $hasService): string
    {
        $modelName = $modelConfig['studlyName'];
        $variable = $modelConfig['camelName'];
        $controllerClass = $modelConfig['classes']['controller'];
        $tableName = $modelConfig['tableName'];
        $modelPlural = $modelConfig['pluralStudlyName'];

        $serviceUsage = $hasService ? 'service' : 'model';
        $constructorParam = $hasService ? "protected {$modelName}Service \${$variable}Service" : '';
        $constructorCode = $hasService ? "public function __construct({$constructorParam}){}" : '';
        $serviceImport = $hasService ? "use App\\Services\\{$modelName}Service;" : '';

        $indexMethod = $hasService
            ? "\$data = \$this->{$variable}Service->getAll();"
            : "\$data = {$modelName}::with([{$this->generateWithRelations($modelData)}])->paginate();";

        $storeMethod = $hasService
            ? "\${$variable} = \$this->{$variable}Service->store(\$request->validated());"
            : $this->generateDirectStoreCode($modelConfig, $modelData);

        $updateMethod = $hasService
            ? "\$this->{$variable}Service->update(\$request->validated(), \${$variable});"
            : $this->generateDirectUpdateCode($modelConfig, $modelData);

        $deleteMethod = $hasService
            ? "\$this->{$variable}Service->delete(\${$variable});"
            : "\${$variable}->delete();";

        return "<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\\{$modelName}Request;
use App\Http\Resources\\{$modelName}\\{$modelName}Collection;
use App\Http\Resources\\{$modelName}\\{$modelName}Resource;
use App\Models\\{$modelName};
{$serviceImport}
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class {$controllerClass} extends Controller implements HasMiddleware
{
    use ApiResponseTrait;

    {$constructorCode}

    public static function middleware(): array
    {
        \$model = '{$tableName}';

        return [
            'auth',
            new Middleware([\"permission:view_\$model\"], only: ['index']),
            new Middleware([\"permission:show_\$model\"], only: ['show']),
            new Middleware([\"permission:create_\$model\"], only: ['store']),
            new Middleware([\"permission:update_\$model\"], only: ['update']),
            new Middleware([\"permission:delete_\$model\"], only: ['destroy']),
        ];
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        {$indexMethod}

        return \$this->success('{$modelPlural} retrieved successfully', {$modelName}Collection::make(\$data));
    }

    public function store({$modelName}Request \$request): \Illuminate\Http\JsonResponse
    {
        try {
            {$storeMethod}

            return \$this->success('{$modelName} created successfully', new {$modelName}Resource(\${$variable}));
        } catch (\Exception \$e) {
            return \$this->failure('{$modelName} creation failed', 500, \$e->getMessage());
        }
    }

    public function show({$modelName} \${$variable}): \Illuminate\Http\JsonResponse
    {
        return \$this->success('{$modelName} retrieved successfully', new {$modelName}Resource(\${$variable}));
    }

    public function update({$modelName}Request \$request, {$modelName} \${$variable}): \Illuminate\Http\JsonResponse
    {
        try {
            {$updateMethod}

            return \$this->success('{$modelName} updated successfully', new {$modelName}Resource(\${$variable}));
        } catch (\Exception \$e) {
            return \$this->failure('{$modelName} update failed', 500, \$e->getMessage());
        }
    }

     public function destroy({$modelName} \${$variable}): \Illuminate\Http\JsonResponse
    {
        try {
             {$deleteMethod}

            return \$this->success('{$modelName} deleted successfully');
        } catch (\Exception \$e) {
            return \$this->failure('{$modelName} deletion failed', 500, \$e->getMessage());
        }
    }
}";
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
        foreach ($modelData['relations'] as $relationConfig) {
            if (isset($relationConfig['makeRequest']) && $relationConfig['makeRequest'] === true) {
                $relatedModel = $relationConfig['model'];
                $imports[] = "use App\\Models\\{$relatedModel};";
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
            $relations[] = "'{$relationName}'";
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

    /**
     * Generate direct store code for controller without service
     */
    private function generateDirectStoreCode(array $modelConfig, array $modelData): string
    {
        $modelName = $modelConfig['studlyName'];
        $variable = $modelConfig['camelName'];

        $code = "\${$variable} = {$modelName}::create(\$request->validated());";
        $code .= $this->generateRelationStoreCode($modelData, $variable);

        return $code;
    }

    /**
     * Generate direct update code for controller without service
     */
    private function generateDirectUpdateCode(array $modelConfig, array $modelData): string
    {
        $variable = $modelConfig['camelName'];

        $code = '$validatedData = $request->validated();';
        $code .= $this->generateRelationUpdateCode($modelData, $variable);
        $code .= "\n        \${$variable}->update(\$validatedData);";

        return $code;
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
