<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use NahidFerdous\LaravelModuleGenerator\Concerns\HandlesFileGeneration;
use NahidFerdous\LaravelModuleGenerator\Contracts\OutputInterface;
use NahidFerdous\LaravelModuleGenerator\Services\Extra\FileUploadCodeGenerator;
use NahidFerdous\LaravelModuleGenerator\Services\RelationCodeGenerator;

class GenerateControllerService
{
    use HandlesFileGeneration;
    private const DEFAULT_GENERATE_CONFIG = [
        'model' => true,
        'migration' => true,
        'controller' => true,
        'service' => true,
        'request' => true,
        'resource' => true,
        'collection' => true,
    ];

    private OutputInterface $command;

    private array $allModels;

    private StubPathResolverService $stubPathResolver;

    private RelationCodeGenerator $relationCodeGenerator;

    public function __construct(OutputInterface $command, array $allModels)
    {
        $this->command = $command;
        $this->allModels = $allModels;
        $this->stubPathResolver = new StubPathResolverService;
        $this->relationCodeGenerator = new RelationCodeGenerator($allModels);
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
            if (! $this->guardAgainstExisting($servicePath, "Service {$modelConfig['classes']['service']}", $force)) {
                return;
            }
            $this->generateService($modelConfig, $modelData, $hasRelations);
        }

        if ($hasController) {
            $controllerPath = app_path("Http/Controllers/{$modelConfig['classes']['controller']}.php");
            if (! $this->guardAgainstExisting($controllerPath, "Controller {$modelConfig['classes']['controller']}", $force)) {
                return;
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

        $relationImports = $this->relationCodeGenerator->generateImports($modelData, $modelConfig['studlyName']);
        $withRelations = $this->relationCodeGenerator->generateWithRelations($modelData);
        $relationStoreCode = $this->relationCodeGenerator->generateStoreCode($modelData, $variable, $modelConfig['originalName']);
        $relationUpdateCode = $this->relationCodeGenerator->generateUpdateCode($modelData, $variable, $modelConfig['originalName']);

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

        $relationImports = $this->relationCodeGenerator->generateImports($modelData, $modelConfig['studlyName']);
        $withRelations = $this->relationCodeGenerator->generateWithRelations($modelData);
        $relationStoreCode = $this->relationCodeGenerator->generateStoreCode($modelData, $variable, $modelConfig['originalName']);
        $relationUpdateCode = $this->relationCodeGenerator->generateUpdateCode($modelData, $variable, $modelConfig['originalName']);

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

}
