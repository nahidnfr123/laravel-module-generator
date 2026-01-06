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
                           {--postman-prefix=api : API prefix for Postman collection}' # api/v1, api/v2, etc.
    ;

    protected $description = 'Generate Laravel module files (model, migration, controller, etc.) from a YAML file';

    private StubPathResolverService $pathResolverService;

    private ?string $currentBackupPath = null;

    private array $parsedYamlData = [];

    public array $generateConfig = [
        'model' => true,
        'migration' => true,
        'controller' => true,
        'service' => true,
        'request' => true,
        'resource' => true,
        'collection' => true,
    ];

    public array $defaultGenerateConfig = [
        'model' => null,
        'migration' => null,
        'controller' => null,
        'service' => null,
        'request' => null,
        'resource' => null,
        'collection' => null,
    ];

    public function handle()
    {
        if ($this->option('force')) {
            $confirmation = $this->ask('This command will replace existing module files and generate module files based on a YAML configuration. Do you want to proceed? (yes/no)', 'no');
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
        if (!$this->option('skip-backup')) {
            $this->currentBackupPath = $backupService->createBackup($models);
            $this->displayBackupInfo();
        }

        foreach ($models as $modelName => $modelData) {
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

        if (!file_exists($path)) {
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

        $modelConfig = $this->buildModelConfiguration($modelName, $modelData);
        // $generateConfig = $this->normalizeGenerateConfiguration($modelData['generate'] ?? true);
        $this->generateConfig = $this->normalizeGenerateConfiguration($modelData['generate'] ?? true);

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

        $defaultGenerate = $this->defaultGenerateConfig;

        $userGenerate = $modelData['generate'] ?? [];

        // Detect unknown keys
        $unknownKeys = array_diff(array_keys($userGenerate), array_keys($defaultGenerate));
        if (!empty($unknownKeys)) {
            throw new \InvalidArgumentException('Unknown generate keys: ' . implode(', ', $unknownKeys));
        }

        // Merge defaults with valid user-provided values
        $generate = array_merge($defaultGenerate, array_intersect_key($userGenerate, $defaultGenerate));

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
            'generate' => $generate,
        ];
    }

    /**
     * Normalize the generate configuration to ensure all keys are present
     */
    private function normalizeGenerateConfiguration($generate): array
    {

        $defaultGenerate = $this->defaultGenerateConfig;

        if ($generate === false) {
            return array_fill_keys(array_keys($defaultGenerate), false);
        }

        if ($generate === true) {
            return $defaultGenerate;
        }

        return array_merge($defaultGenerate, $generate);
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
        if ($generateConfig['model']) {
            $modelPath = app_path("Models/{$modelConfig['studlyName']}.php");
            if (File::exists($modelPath) && !$force) {
                $this->warn("⚠️ Model already exists: {$modelConfig['studlyName']}");

                return;
            }

            if (File::exists($modelPath)) {
                File::delete($modelPath);
                $this->warn("⚠️ Deleted existing model: {$modelConfig['studlyName']}");
            }

            (new GenerateModelService($this))
                ->generateModel($modelConfig['studlyName'], $modelConfig['fields'], $modelConfig['relations'], $generateConfig);
        }

        // Check if migration generation is enabled
        // if ($generateConfig['migration'] === true || $generateConfig['migration'] === false) {
        if ($generateConfig['migration'] === false) {
            $this->deleteMigrationFile($modelConfig);
        }
        if ($generateConfig['migration'] === true) {
            (new GenerateMigrationService($this))
                ->generateMigration($modelConfig['studlyName'], $modelConfig['fields']);
        }
    }

    protected function deleteMigrationFile($modelConfig)
    {
        $migrationPattern = database_path("migrations/*create_{$modelConfig['tableName']}_table.php");
        $migrationFiles = glob($migrationPattern);
        // Delete existing migration files if they exist
        if (!empty($migrationFiles)) {
            foreach ($migrationFiles as $file) {
                File::delete($file);
                $this->warn('⚠️ Deleted existing migration: ' . basename($file));
            }
        }
    }

    /**
     * Generate optional files based on configuration
     */
    private function generateOptionalFiles(array $modelConfig): void
    {
        // Use the model-specific generate config instead of the global one
        $generateConfig = $modelConfig['generate'];
        $force = $this->option('force');

        if ($generateConfig['request']) {
            (new GenerateRequestService($this, $this->parseYamlFile(), $generateConfig))
                ->handleRequestGeneration($modelConfig, $force);
        }

        $resourceCollectionService = new GenerateResourceCollectionService($this, $generateConfig);
        if ($generateConfig['collection']) {
            $resourceCollectionService->handleCollectionGeneration($modelConfig, $force);
        }
        if ($generateConfig['resource']) {
            $resourceCollectionService->handleResourceGeneration($modelConfig, $force);
        }

        if ($generateConfig['service'] || $generateConfig['controller']) {
            $modelData = $this->getCurrentModelData($modelConfig['originalName']);
            $controllerService = new GenerateControllerService($this, $this->parsedYamlData);
            $controllerService->generateControllerAndService($modelConfig, $modelData, $force);
        }

        if ($generateConfig['controller']) {
            (new AppendRouteService($this))->appendRoute($modelConfig['tableName'], $modelConfig['classes']['controller']);
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

        if (!$config['skipPostman']) {
            $this->generatePostmanCollection($config['path']);
        }

        if (!$config['skipDbDiagram']) {
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
            // $this->info('🥵 Postman collection generated successfully!');
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
            $this->warn('⚠️ Failed to run Laravel Pint: ' . $e->getMessage());
        }
    }
}
