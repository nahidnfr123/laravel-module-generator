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
                           {--postman-prefix=api/v1 : API prefix for Postman collection}';

    protected $description = 'Generate Laravel module files (model, migration, controller, etc.) from a YAML file';

    private StubPathResolverService $pathResolverService;

    private ?string $currentBackupPath = null;

    private array $parsedYamlData = [];

    private array $config = [];

    private const DEFAULT_GENERATE_CONFIG = [
        'model' => true,
        'migration' => true,
        'controller' => true,
        'service' => true,
        'request' => true,
        'resource' => true,
        'collection' => true,
    ];

    public function handle(): int
    {
        if ($this->option('force')) {
            $confirmation = $this->ask('This command will replace existing module files and generate module files based on a YAML configuration. Do you want to proceed? (yes/no)', 'yes');
            if (strtolower($confirmation) !== 'yes') {
                $this->info('Command cancelled.');

                return CommandAlias::SUCCESS;
            }
        }
        try {
            $this->init();
            $this->createBackupIfNeeded();
            $this->processModules();
            $this->generateAdditionalFiles();

            $this->displaySuccessMessage();

            return CommandAlias::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return CommandAlias::FAILURE;
        }
    }

    /**
     * Initialize the command with configuration and validation
     */
    private function init(): void
    {
        $this->pathResolverService = new StubPathResolverService;
        $this->config = $this->validateAndGetConfiguration();
        $this->parsedYamlData = $this->parseYamlFile();
    }

    /**
     * Create backup if not skipped
     */
    private function createBackupIfNeeded(): void
    {
        if (! $this->option('skip-backup')) {
            $backupService = new BackupService($this);
            $this->currentBackupPath = $backupService->createBackup($this->parsedYamlData);
            $this->displayBackupInfo();
        }
    }

    /**
     * Process all models from YAML configuration
     *
     * @throws \Exception
     */
    private function processModules(): void
    {
        foreach ($this->parsedYamlData as $modelName => $modelData) {
            $this->processModule($modelName, $modelData);
        }
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
     * Display final success message
     */
    private function displaySuccessMessage(): void
    {
        $this->newLine();
        $this->info('🎉 All modules generated successfully!');

        if ($this->currentBackupPath) {
            $this->comment("💾 Backup available at: {$this->currentBackupPath}");
        }
    }

    /**
     * Validate options and get configuration
     */
    private function validateAndGetConfiguration(): array
    {
        $defaultPath = config('module-generator.models_path');
        $path = $this->option('file') ?? $defaultPath;

        if (! $path) {
            throw new \InvalidArgumentException('YAML file path is required. Use --file option or set module-generator.models_path config.');
        }

        if (! file_exists($path)) {
            throw new \InvalidArgumentException("YAML file not found at: $path");
        }

        if (! is_readable($path)) {
            throw new \InvalidArgumentException("YAML file is not readable: $path");
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
        try {
            $data = Yaml::parseFile($this->config['path']);

            if (! is_array($data) || empty($data)) {
                throw new \InvalidArgumentException('YAML file must contain valid model configurations');
            }

            return $data;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Failed to parse YAML file: {$e->getMessage()}");
        }
    }

    /**
     * Process a single model from the YAML configuration
     *
     * @throws \Exception
     */
    private function processModule(string $modelName, array $modelData): void
    {
        $this->info("Generating files for: $modelName");

        try {
            $modelConfig = $this->buildModelConfiguration($modelName, $modelData);
            $generateConfig = $this->normalizeGenerateConfiguration($modelData['generate'] ?? true);

            $this->generateModelFiles($modelConfig, $generateConfig);

            $this->newLine();
            $this->info("✅ Module generated for $modelName");
            $this->newLine();

        } catch (\Exception $e) {
            $this->error("Failed to generate module for $modelName: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Generate all files for a model
     */
    private function generateModelFiles(array $modelConfig, array $generateConfig): void
    {
        $this->generateModelAndMigration($modelConfig, $generateConfig);
        $this->generateOptionalFiles($modelConfig, $generateConfig);
    }

    /**
     * Build configuration object for a model
     */
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

    /**
     * Normalize the generate configuration to ensure all keys are present
     */
    private function normalizeGenerateConfiguration($generate): array
    {
        if ($generate === false) {
            return array_fill_keys(array_keys(self::DEFAULT_GENERATE_CONFIG), false);
        }

        if ($generate === true || $generate === null) {
            return self::DEFAULT_GENERATE_CONFIG;
        }

        if (is_array($generate)) {
            return array_merge(self::DEFAULT_GENERATE_CONFIG, $generate);
        }

        throw new \InvalidArgumentException('Generate configuration must be boolean or array');
    }

    /**
     * Generate model and migration files
     */
    private function generateModelAndMigration(array $modelConfig, array $generateConfig): void
    {
        $force = $this->config['force'];

        if ($generateConfig['model']) {
            $this->generateModelFile($modelConfig, $force);
        }

        if ($generateConfig['migration']) {
            $this->generateMigrationFile($modelConfig, $force);
        }
    }

    /**
     * Generate model file
     */
    private function generateModelFile(array $modelConfig, bool $force): void
    {
        $modelPath = app_path("Models/{$modelConfig['studlyName']}.php");

        if (File::exists($modelPath) && ! $force) {
            $this->warn("⚠️ Model already exists: {$modelConfig['studlyName']}");

            return;
        }

        if (File::exists($modelPath)) {
            File::delete($modelPath);
            $this->warn("⚠️ Deleted existing model: {$modelConfig['studlyName']}");
        }

        (new GenerateModelService($this))
            ->generateModel($modelConfig['studlyName'], $modelConfig['fields'], $modelConfig['relations']);
    }

    /**
     * Generate migration file
     */
    private function generateMigrationFile(array $modelConfig, bool $force): void
    {
        $migrationPattern = database_path("migrations/*create_{$modelConfig['tableName']}_table.php");
        $migrationFiles = glob($migrationPattern);

        // Delete existing migration files if they exist
        if (! empty($migrationFiles)) {
            foreach ($migrationFiles as $file) {
                File::delete($file);
                $this->warn('⚠️ Deleted existing migration: '.basename($file));
            }
        }

        (new GenerateMigrationService($this))
            ->generateMigration($modelConfig['studlyName'], $modelConfig['fields']);
    }

    /**
     * Generate optional files based on configuration
     */
    private function generateOptionalFiles(array $modelConfig, array $generateConfig): void
    {
        $force = $this->config['force'];

        if ($generateConfig['request']) {
            $this->generateRequestFile($modelConfig, $force);
        }

        if ($generateConfig['collection'] || $generateConfig['resource']) {
            $this->generateResourceFiles($modelConfig, $generateConfig, $force);
        }

        if ($generateConfig['service'] || $generateConfig['controller']) {
            $modelData = $this->getCurrentModelData($modelConfig['originalName']);
            $controllerService = new GenerateControllerService($this, $this->parsedYamlData);
            $controllerService->generateControllerAndService($modelConfig, $modelData, $force);
        }

        if ($generateConfig['controller']) {
            $this->appendRoute($modelConfig);
        }
    }

    /**
     * Generate request file
     */
    private function generateRequestFile(array $modelConfig, bool $force): void
    {
        (new GenerateRequestService($this, $this->parsedYamlData, self::DEFAULT_GENERATE_CONFIG))
            ->handleRequestGeneration($modelConfig, $force);
    }

    /**
     * Generate resource files
     */
    private function generateResourceFiles(array $modelConfig, array $generateConfig, bool $force): void
    {
        $resourceCollectionService = new GenerateResourceCollectionService($this, $generateConfig);

        if ($generateConfig['collection']) {
            $resourceCollectionService->handleCollectionGeneration($modelConfig, $force);
        }

        if ($generateConfig['resource']) {
            $resourceCollectionService->handleResourceGeneration($modelConfig, $force);
        }
    }

    /**
     * Append route for controller
     */
    private function appendRoute(array $modelConfig): void
    {
        (new AppendRouteService($this))
            ->appendRoute($modelConfig['tableName'], $modelConfig['classes']['controller']);
    }

    /**
     * Get current model data from parsed YAML
     */
    private function getCurrentModelData(string $modelName): array
    {
        return $this->parsedYamlData[$modelName] ?? [];
    }

    /**
     * Generate additional files like Postman collection and DB diagram
     */
    private function generateAdditionalFiles(): void
    {
        if (! $this->config['skipPostman']) {
            $this->generatePostmanCollection();
        }

        if (! $this->config['skipDbDiagram']) {
            $this->generateDbDiagram();
        }
    }

    /**
     * Generate Postman collection
     */
    private function generatePostmanCollection(): void
    {
        $this->newLine();
        $this->info('🚀 Generating Postman collection...');

        try {
            $result = $this->call('postman:generate', [
                '--file' => $this->config['path'],
                '--base-url' => $this->option('postman-base-url'),
                '--prefix' => $this->option('postman-prefix'),
            ]);

            if ($result === CommandAlias::SUCCESS) {
                $this->info('✅ Postman collection generated successfully!');
            } else {
                $this->warn('⚠️ Failed to generate Postman collection');
            }
        } catch (\Exception $e) {
            $this->warn("⚠️ Failed to generate Postman collection: {$e->getMessage()}");
        }
    }

    /**
     * Generate database diagram
     */
    private function generateDbDiagram(): void
    {
        $this->newLine();
        $this->info('🚀 Generating DB diagram...');

        try {
            $result = $this->call('dbdiagram:generate', [
                '--file' => $this->config['path'],
                '--output' => 'module/dbdiagram.dbml',
            ]);

            if ($result === CommandAlias::SUCCESS) {
                $this->info('✅ DB diagram generated successfully at module/dbdiagram.dbml');
            } else {
                $this->warn('⚠️ Failed to generate DB diagram');
            }
        } catch (\Exception $e) {
            $this->warn("⚠️ Failed to generate DB diagram: {$e->getMessage()}");
        }
    }
}
