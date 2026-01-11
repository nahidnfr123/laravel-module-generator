<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Services\AppendRouteService;
use NahidFerdous\LaravelModuleGenerator\Services\BackupService;
use NahidFerdous\LaravelModuleGenerator\Services\GenerateControllerService;
use NahidFerdous\LaravelModuleGenerator\Services\GenerateMigrationService;
use NahidFerdous\LaravelModuleGenerator\Services\GenerateModelService;
use NahidFerdous\LaravelModuleGenerator\Services\GenerateRequestService;
use NahidFerdous\LaravelModuleGenerator\Services\GenerateResourceCollectionService;
use NahidFerdous\LaravelModuleGenerator\Services\GenerateSeederService;
use NahidFerdous\LaravelModuleGenerator\Services\StubPathResolverService;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class GenerateModuleFromYaml extends Command
{
    protected $signature = 'module:generate
                           {--force : Overwrite existing files}
                           {--file= : Path to a YAML file}
                           {--skip-postman : Skip Postman collection generation}
                           {--skip-dbdiagram : Skip DB diagram generation}
                           {--skip-backup : Skip backup creation}
                           {--postman-base-url={{base-url}} : Base URL for Postman collection}
                           {--postman-prefix=api : API prefix for Postman collection}'; // api/v1, api/v2, etc.

    protected $description = 'Generate Laravel module files (model, migration, controller, etc.) from a YAML file';

    private StubPathResolverService $pathResolverService;

    private ?string $currentBackupPath = null;

    private array $parsedYamlData = [];

    public array $defaultGenerateConfig = ['model', 'migration', 'controller', 'service', 'request', 'resource', 'collection', 'seeder'];

    public array $generateConfig = [];

    public function handle()
    {
        if ($this->option('force')) {
            $confirmation = $this->ask('This command will replace existing module files and generate module files based on a YAML configuration. Do you want to proceed? (yes/no)', 'no');
            //            $confirmation = 'yes';
            if (strtolower($confirmation) !== 'yes' && strtolower($confirmation) !== 'y' && strtolower($confirmation) !== 'Y') {
                $this->info('Command cancelled.');

                return CommandAlias::SUCCESS;
            }
        }

        $backupService = new BackupService($this);
        $this->pathResolverService = new StubPathResolverService;
        $this->validateAndGetConfiguration();
        $this->parsedYamlData = $this->parseYamlFile();
        $models = $this->parseYamlFile();

        // Create backup unless explicitly skipped
        if (! $this->option('skip-backup')) {
            $this->currentBackupPath = $backupService->createBackup($models);
            $this->displayBackupInfo();
        }

        foreach ($models as $modelName => $modelData) {
            $this->generateConfig = [];
            $this->processModel($modelName, $modelData);
        }

        $this->generateAdditionalFiles();

        $this->newLine();
        $this->info('🎉 All modules generated successfully!');

        // Run Laravel Pint to format the generated code
        $this->runPint();
        $this->newLine();

        return CommandAlias::SUCCESS;
    }

    /**
     * Display backup information to user
     */
    private function displayBackupInfo(): void
    {
        if ($this->currentBackupPath) {
            $this->newLine();
            $this->info("💾 Backup created at: {$this->currentBackupPath}");
            $this->info("💡 Use 'php artisan module:rollback' to restore if needed");
            $this->newLine();
        }
    }

    /**
     * Validate options and get configuration
     */
    private function validateAndGetConfiguration(): array
    {
        $defaultPath = config('module-generator.models_path');
        $path = $this->option('file') ?? $defaultPath;

        if (! file_exists($path)) {
            $this->error("YAML file not found at: $path");
            exit(CommandAlias::FAILURE);
        }

        return [
            'path' => $path,
            'force' => $this->option('force'),
            'skipPostman' => $this->option('skip-postman'),
            'skipDbDiagram' => $this->option('skip-dbdiagram'),
        ];
    }

    /**
     * Parse the YAML configuration file
     */
    private function parseYamlFile(): array
    {
        $config = $this->validateAndGetConfiguration();

        return Yaml::parseFile($config['path']);
    }

    /**
     * Process a single model from the YAML configuration
     */
    private function processModel(string $modelName, array $modelData): void
    {
        $this->info("Generating files for: $modelName");

        // All available generation options
        $allGenerateOptions = $this->defaultGenerateConfig;

        // Determine what to generate based on priority:
        // 1. generate (highest priority)
        // 2. generate_only (second priority)
        // 3. generate_except (third priority)
        // 4. Nothing specified = generate nothing (default)

        $generateOptions = [];

        // Priority 1: Check for 'generate'
        if (isset($modelData['generate'])) {
            if ($modelData['generate'] === 'all') {
                $generateOptions = $allGenerateOptions;
            } elseif (is_string($modelData['generate'])) {
                $generateOptions = array_map('trim', explode(',', $modelData['generate']));
            } elseif (is_array($modelData['generate'])) {
                $generateOptions = $modelData['generate'];
            }
        } // Priority 2: Check for 'generate_only'
        elseif (isset($modelData['generate_only'])) {
            if (is_string($modelData['generate_only'])) {
                $generateOptions = array_map('trim', explode(',', $modelData['generate_only']));
            } elseif (is_array($modelData['generate_only'])) {
                $generateOptions = $modelData['generate_only'];
            }
            // Only keep valid options
            $generateOptions = array_intersect($allGenerateOptions, $generateOptions);
        } // Priority 3: Check for 'generate_except'
        elseif (isset($modelData['generate_except'])) {
            // Start with all options
            $generateOptions = $allGenerateOptions;

            if (is_string($modelData['generate_except'])) {
                $exceptOptions = array_map('trim', explode(',', $modelData['generate_except']));
            } elseif (is_array($modelData['generate_except'])) {
                $exceptOptions = $modelData['generate_except'];
            } else {
                $exceptOptions = [];
            }

            // Remove excepted items from the list
            $generateOptions = array_diff($generateOptions, $exceptOptions);
        } // Default: If none of the above are specified, generate nothing
        else {
            $generateOptions = [];
        }

        // Re-index array to ensure clean numeric keys
        $generateOptions = array_values($generateOptions);

        // If nothing to generate, skip this model
        if (empty($generateOptions)) {
            $this->warn("⚠️ Skipping $modelName - no generation options specified");
            $this->newLine();

            return;
        }

        $modelData['generate'] = $generateOptions;

        $modelConfig = $this->buildModelConfiguration($modelName, $modelData);
        $this->generateConfig = array_intersect($this->defaultGenerateConfig, $generateOptions);

        $this->generateModelAndMigration($modelConfig);
        $this->generateOptionalFiles($modelConfig);

        $this->newLine();
        $this->info("🎉 Module generated for $modelName");
        $this->newLine();
        sleep(1);
    }

    /**
     * Build configuration object for a model
     */
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
            'primaryKey' => $modelData['primaryKey'] ?? 'id',
            'softDeletes' => array_key_exists('deleted_at', $modelData['fields'] ?? []),
            'classes' => [
                'controller' => "{$studlyModelName}Controller",
                'service' => "{$studlyModelName}Service",
                'collection' => "{$studlyModelName}Collection",
                'resource' => "{$studlyModelName}Resource",
                'request' => "{$studlyModelName}Request",
            ],
            'generate' => $modelData['generate'],
        ];
    }

    /**
     * Generate model and migration files
     */
    private function generateModelAndMigration(array $modelConfig): void
    {
        $force = $this->option('force');

        // Use the model-specific generate config instead of the global one
        $generateConfig = $modelConfig['generate'];

        // Check if model generation is enabled
        if (in_array('model', $generateConfig, true)) {
            $modelPath = app_path("Models/{$modelConfig['studlyName']}.php");
            if (File::exists($modelPath) && ! $force) {
                $this->warn("⚠️ Model already exists: {$modelConfig['studlyName']}");

                return;
            }

            if (File::exists($modelPath)) {
                File::delete($modelPath);
                $this->warn("⚠️ Deleted existing model: {$modelConfig['studlyName']}");
            }

            new GenerateModelService($this)
                ->generateModel(
                    $modelConfig['studlyName'],
                    $modelConfig['fields'],
                    $modelConfig['relations'],
                    $generateConfig,
                    $modelConfig['primaryKey'],
                    $modelConfig['softDeletes']
                );
        }
        if (in_array('migration', $generateConfig, true)) {
            new GenerateMigrationService($this)->generateMigration($modelConfig['studlyName'], $modelConfig['fields']);
        }
    }

    /**
     * Generate optional files based on configuration
     */
    private function generateOptionalFiles(array $modelConfig): void
    {
        // Use the model-specific generated config instead of the global one
        $generateConfig = $modelConfig['generate'];
        $force = $this->option('force');

        if (in_array('request', $generateConfig, true)) {
            new GenerateRequestService($this, $this->parseYamlFile(), $generateConfig)
                ->handleRequestGeneration($modelConfig, $force);
        }

        $resourceCollectionService = new GenerateResourceCollectionService($this, $generateConfig);
        if (in_array('collection', $generateConfig, true)) {
            $resourceCollectionService->handleCollectionGeneration($modelConfig, $force);
        }
        if (in_array('resource', $generateConfig, true)) {
            $resourceCollectionService->handleResourceGeneration($modelConfig, $force);
        }

        if (in_array('service', $generateConfig, true) && in_array('controller', $generateConfig, true)) {
            $modelData = $this->getCurrentModelData($modelConfig['originalName']);
            $controllerService = new GenerateControllerService($this, $this->parsedYamlData);
            $controllerService->generateControllerAndService($modelConfig, $modelData, $force);
        }

        if (in_array('controller', $generateConfig, true)) {
            new AppendRouteService($this)->appendRoute($modelConfig['tableName'], $modelConfig['classes']['controller']);
        }

        if (in_array('seeder', $generateConfig, true)) {
            new GenerateSeederService($this)->generateSeeder($modelConfig['studlyName'], $modelConfig['fields'], $force);
        }
    }

    /**
     * Get current model data from parsed YAML
     */
    private function getCurrentModelData(string $modelName): array
    {
        $models = $this->parseYamlFile();

        return $models[$modelName] ?? [];
    }

    /**
     * Generate additional files like Postman collection and DB diagram
     */
    private function generateAdditionalFiles(): void
    {
        $config = $this->validateAndGetConfiguration();

        if (! $config['skipPostman']) {
            $this->generatePostmanCollection($config['path']);
        }

        if (! $config['skipDbDiagram']) {
            $this->generateDbDiagram($config['path']);
        }
    }

    /**
     * Generate Postman collection
     */
    private function generatePostmanCollection(string $path): void
    {
        $this->newLine();
        $this->info('🚀 Generating Postman collection...');

        $baseUrl = $this->option('postman-base-url');
        $prefix = $this->option('postman-prefix');

        $result = $this->call('postman:generate', [
            '--file' => $path,
            '--base-url' => $baseUrl,
            '--prefix' => $prefix,
        ]);

        if ($result === CommandAlias::SUCCESS) {
            $this->newLine();
            // $this->info('🥵 a Postman collection generated successfully!');
        } else {
            $this->warn('⚠️ Failed to generate Postman collection');
        }
    }

    /**
     * Generate database diagram
     */
    private function generateDbDiagram(string $path): void
    {
        $this->newLine();
        $this->info('🚀 Generating DB diagram...');

        $result = $this->call('dbdiagram:generate', [
            '--file' => $path,
            '--output' => 'module/dbdiagram.dbml',
        ]);

        if ($result === CommandAlias::SUCCESS) {
            $this->newLine();
            // $this->info('🤧 DB diagram generated successfully at module/dbdiagram.dbml');
        } else {
            $this->warn('⚠️ Failed to generate DB diagram');
        }
    }

    private function runPint(): void
    {
        $this->newLine();
        $this->info('🎨 Running Laravel Pint to format generated code...');

        try {
            $process = new Process(['./vendor/bin/pint', '--quiet']);
            $process->run();

            if ($process->isSuccessful()) {
                $this->info('✨ Code formatting completed successfully!');
            } else {
                $this->warn('⚠️ Code formatting completed with some issues');
                $this->warn($process->getErrorOutput());
            }
        } catch (\Exception $e) {
            $this->warn('⚠️ Failed to run Laravel Pint: '.$e->getMessage());
        }
    }
}
