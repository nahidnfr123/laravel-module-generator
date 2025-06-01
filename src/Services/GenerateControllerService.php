<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateControllerService
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

            if (File::exists($servicePath) && !$force) {
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

            if (File::exists($controllerPath) && !$force) {
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
        if (!isset($modelData['relations']) || !is_array($modelData['relations'])) {
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
        } else if ($hasService) {
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
            '{{ relationStore }}' => '',
            '{{ relationUpdate }}' => '',
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
        \$with = [{$withRelations}];

        return {$modelName}::when(!empty(\$with), function (\$query) use (\$with) {
            return \$query->with(\$with);
        })->get();
    }

    /**
     * Get {$modelName} by ID
     */
    public function getById(\$id)
    {
        \$with = [{$withRelations}];

        return {$modelName}::when(!empty(\$with), function (\$query) use (\$with) {
            return \$query->with(\$with);
        })->findOrFail(\$id);
    }

    /**
     * Create a new {$modelName}
     */
    public function store(array \$data)
    {
        \${$variable} = {$modelName}::create(\$data);
{$relationStoreCode}

        return \$this->getById(\${$variable}->id);
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

        return \$this->getById(\${$variable}->id);
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
        \$data = {$modelName}::all();

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
            : "\$data = {$modelName}::with([{$this->generateWithRelations($modelData)}])->get();";

        $storeMethod = $hasService
            ? "\${$variable} = \$this->{$variable}Service->store(\$request->validated());"
            : $this->generateDirectStoreCode($modelConfig, $modelData);

        $updateMethod = $hasService
            ? "\$this->{$variable}Service->update(\${$variable}, \$request->validated());"
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
        if (!isset($modelData['relations'])) {
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
        if (!isset($modelData['relations'])) {
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
        if (!isset($modelData['relations'])) {
            return '';
        }

        $code = '';
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (!isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n        // Handle {$relationName} relation";
                    $code .= "\n        if (isset(\$data['{$relationKey}']) && is_array(\$data['{$relationKey}'])) {";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->createMany(\$data['{$relationKey}']);";
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
        if (!isset($modelData['relations'])) {
            return '';
        }

        $code = '';
        foreach ($modelData['relations'] as $relationName => $relationConfig) {
            if (!isset($relationConfig['makeRequest']) || $relationConfig['makeRequest'] !== true) {
                continue;
            }

            $relationKey = Str::snake($relationName);
            $relationType = $relationConfig['type'];

            switch ($relationType) {
                case 'hasMany':
                    $code .= "\n        // Handle {$relationName} relation update";
                    $code .= "\n        if (isset(\$validatedData['{$relationKey}']) && is_array(\$validatedData['{$relationKey}'])) {";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->delete();";
                    $code .= "\n            \${$modelVariable}->{$relationName}()->createMany(\$validatedData['{$relationKey}']);";
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

        $code = "\$validatedData = \$request->validated();";
        $code .= $this->generateRelationUpdateCode($modelData, $variable);
        $code .= "\n        \${$variable}->update(\$validatedData);";

        return $code;
    }
}
