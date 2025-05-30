<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Services\AppendRouteService;
use NahidFerdous\LaravelModuleGenerator\Services\GenerateControllerService;
use NahidFerdous\LaravelModuleGenerator\Services\BackupService;
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

    private BackupService $backupService;

    private StubPathResolverService $pathResolverService;

    private ?string $currentBackupPath = null;

    public array $generateConfig = [
        'model' => true,
        'migration' => true,
        'controller' => true,
        'service' => true,
        'request' => true,
        'resource' => true,
        'collection' => true,
    ];

    public function handle()
    {
        //        if ($this->option('force')) {
        //            $confirmation = $this->ask('This command will replace existing module files and generate module files based on a YAML configuration. Do you want to proceed? (yes/no)', 'yes');
        //            if (strtolower($confirmation) !== 'yes') {
        //                $this->info('Command cancelled.');
        //                return CommandAlias::SUCCESS;
        //            }
        //        }

        $this->backupService = new BackupService($this);
        $this->pathResolverService = new StubPathResolverService;
        $this->validateAndGetConfiguration();
        $models = $this->parseYamlFile();

        // Create backup unless explicitly skipped
        if (!$this->option('skip-backup')) {
            //$this->currentBackupPath = $this->backupService->createBackup($models);
            //$this->displayBackupInfo();
        }

        foreach ($models as $modelName => $modelData) {
            $this->processModel($modelName, $modelData);
        }

        $this->generateAdditionalFiles();

        $this->newLine();
        $this->info('🎉 All modules generated successfully!');

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
        //        $generateConfig = $this->normalizeGenerateConfiguration($modelData['generate'] ?? true);
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
        $defaultGenerate = [
            'model' => true,
            'migration' => true,
            'controller' => true,
            'service' => true,
            'request' => true,
            'resource' => true,
            'collection' => true,
        ];

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
        $modelPath = app_path("Models/{$modelConfig['studlyName']}.php");
        $migrationPattern = database_path("migrations/*create_{$modelConfig['tableName']}_table.php");
        $migrationFiles = glob($migrationPattern);
        $force = $this->option('force');

        // Check if model generation is enabled
        if ($this->generateConfig['model']) {
            if (File::exists($modelPath) && !$force) {
                $this->warn("⚠️ Model already exists: {$modelConfig['studlyName']}");

                return;
            }

            if (File::exists($modelPath)) {
                File::delete($modelPath);
                $this->warn("⚠️ Deleted existing model: {$modelConfig['studlyName']}");
            }

            $this->generateModel($modelConfig['studlyName'], $modelConfig['fields'], $modelConfig['relations']);
        }

        // Check if migration generation is enabled
        if ($this->generateConfig['migration']) {
            // Delete existing migration files if they exist
            if (!empty($migrationFiles)) {
                foreach ($migrationFiles as $file) {
                    File::delete($file);
                    $this->warn('⚠️ Deleted existing migration: ' . basename($file));
                }
            }

            $this->generateMigration($modelConfig['studlyName'], $modelConfig['fields']);
        }
    }

    /**
     * Generate optional files based on configuration
     */
    private function generateOptionalFiles(array $modelConfig): void
    {
        $generateConfig = $this->generateConfig;
        $force = $this->option('force');

        if ($generateConfig['request']) {
            (new GenerateRequestService($this, $this->parseYamlFile(), $this->generateConfig))
                ->handleRequestGeneration($modelConfig, $force);
        }

        $resourceCollectionService = new GenerateResourceCollectionService($this, $generateConfig);
        if ($generateConfig['collection']) {
            $resourceCollectionService->handleCollectionGeneration($modelConfig, $force);
        }

        if ($generateConfig['resource']) {
            $resourceCollectionService->handleResourceGeneration($modelConfig, $force);
        }

        if ($generateConfig['service']) {
            $this->handleServiceGeneration($modelConfig, $force);
        }

        if ($generateConfig['controller']) {
            $this->handleControllerGeneration($modelConfig, $force);
            (new AppendRouteService($this))->appendRoute($modelConfig['tableName'], $modelConfig['classes']['controller']);
        }
    }

    /**
     * Handle controller file generation (Updated)
     */
    private function handleControllerGeneration(array $modelConfig, bool $force): void
    {
        if ($this->generateConfig['controller']) {
            $controllerPath = app_path("Http/Controllers/{$modelConfig['classes']['controller']}.php");
            if (File::exists($controllerPath) && !$force) {
                $this->warn("⚠️ Controller already exists: {$modelConfig['classes']['controller']}");

                return;
            }

            if (File::exists($controllerPath)) {
                File::delete($controllerPath);
                $this->warn("⚠️ Deleted existing controller: {$modelConfig['classes']['controller']}");
            }

            // Use the new service for controller generation
            $controllerService = new GenerateControllerService($this, $this->parseYamlFile());
            $modelData = $this->getCurrentModelData($modelConfig['originalName']);

            $controllerService->generateController($modelConfig, $modelData);
        }
    }

    /**
     * Handle service file generation (Updated)
     */
    private function handleServiceGeneration(array $modelConfig, bool $force): void
    {
        if ($this->generateConfig['service']) {
            $servicePath = app_path("Services/{$modelConfig['classes']['service']}.php");

            if (File::exists($servicePath) && !$force) {
                $this->warn("⚠️ Service already exists: {$modelConfig['classes']['service']}");

                return;
            }

            if (File::exists($servicePath)) {
                File::delete($servicePath);
                $this->warn("⚠️ Deleted existing service: {$modelConfig['classes']['service']}");
            }

            // Use the new service for service generation
            $controllerService = new GenerateControllerService($this, $this->parseYamlFile());
            $modelData = $this->getCurrentModelData($modelConfig['originalName']);

            $controllerService->generateService($modelConfig, $modelData);
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
            $this->info('🥵 Postman collection generated successfully!');
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
            $this->info('🤧 DB diagram generated successfully at module/dbdiagram.dbml');
        } else {
            $this->warn('⚠️ Failed to generate DB diagram');
        }
    }

    /**
     * Generate model file with fillable fields and relationships
     */
    protected function generateModel(string $modelName, array $fields, array $relations = []): void
    {
        Artisan::call('make:model', ['name' => $modelName, '--migration' => true]);

        $modelPath = app_path("Models/{$modelName}.php");
        if (!File::exists($modelPath)) {
            $this->warn("⚠️ Model file not found for: {$modelName}");

            return;
        }

        $fillableArray = $this->buildFillableArray($fields);
        $relationshipMethods = $this->buildRelationshipMethods($relations);

        $this->insertModelContent($modelPath, $modelName, $fillableArray, $relationshipMethods);

        $this->info("🤫 Fillable fields and relationships added to {$modelName} model");
    }

    /**
     * Build fillable array string for model
     */
    private function buildFillableArray(array $fields): string
    {
        $fillableFields = array_map(fn($field) => "        '{$field}'", array_keys($fields));

        return "protected \$fillable = [\n" . implode(",\n", $fillableFields) . ",\n    ];";
    }

    /**
     * Build relationship methods for model
     */
    private function buildRelationshipMethods(array $relations): string
    {
        if (empty($relations)) {
            return '';
        }

        $relationshipMethods = '';
        foreach ($relations as $relationName => $meta) {
            $relationName = Str::camel($relationName);
            $type = $meta['type'];
            $relatedModel = $meta['model'];

            $relationshipMethods .= <<<PHP


    public function {$relationName}()
    {
        return \$this->{$type}({$relatedModel}::class);
    }
PHP;
        }

        return $relationshipMethods;
    }

    /**
     * Insert fillable and relationship content into model file
     */
    private function insertModelContent(string $modelPath, string $modelName, string $fillableArray, string $relationshipMethods): void
    {
        $modelContent = File::get($modelPath);

        $modelContent = preg_replace(
            '/(class\s+' . $modelName . '\s+extends\s+Model\s*\{)/',
            "$1\n\n    {$fillableArray}\n{$relationshipMethods}\n",
            $modelContent
        );

        File::put($modelPath, $modelContent);
    }

    /**
     * Generate migration file with field definitions
     */
    protected function generateMigration(string $modelName, array $fields, array $uniqueConstraints = []): void
    {
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $migrationFiles = glob(database_path('migrations/*create_' . $tableName . '_table.php'));

        if (empty($migrationFiles)) {
            $this->warn("Migration file not found for $modelName.");

            return;
        }

        $migrationFile = $migrationFiles[0];
        $fieldStub = $this->buildMigrationFields($fields, $uniqueConstraints);

        $this->updateMigrationFile($migrationFile, $fieldStub);

        $this->info("✅ Migration file updated for $modelName");
    }

    /**
     * Build field definitions for migration
     */
    private function buildMigrationFields(array $fields, array $uniqueConstraints): string
    {
        $fieldStub = '';

        foreach ($fields as $name => $definition) {
            $fieldStub .= $this->buildSingleFieldDefinition($name, $definition) . ";\n            ";
        }

        $fieldStub .= $this->buildUniqueConstraints($uniqueConstraints);

        return $fieldStub;
    }

    /**
     * Build a single field definition for migration
     */
    private function buildSingleFieldDefinition(string $name, string $definition): string
    {
        $parts = explode(':', $definition);
        $type = array_shift($parts);

        if ($type === 'foreignId') {
            return $this->buildForeignIdField($name, $parts);
        }

        return $this->buildRegularField($name, $type, $parts);
    }

    /**
     * Build foreign ID field definition
     */
    private function buildForeignIdField(string $name, array $parts): string
    {
        $references = array_shift($parts);
        $modifiers = $parts;

        $line = "\$table->foreignId('$name')";

        foreach ($modifiers as $modifier) {
            if (str_starts_with($modifier, 'default(')) {
                $line .= "->{$modifier}";
            } else {
                $line .= "->$modifier()";
            }
        }

        return $line . "->constrained('$references')->cascadeOnDelete()";
    }

    /**
     * Build regular field definition
     */
    private function buildRegularField(string $name, string $type, array $parts): string
    {
        $line = "\$table->$type('$name')";

        foreach ($parts as $modifier) {
            $line .= $this->processFieldModifier($modifier);
        }

        return $line;
    }

    /**
     * Process individual field modifier
     */
    private function processFieldModifier(string $modifier): string
    {
        if (str_starts_with($modifier, 'default(')) {
            return "->{$modifier}";
        }

        if (str_starts_with($modifier, 'default')) {
            return $this->processDefaultModifier($modifier);
        }

        if (in_array($modifier, ['nullable', 'unique'])) {
            return "->$modifier()";
        }

        return '';
    }

    /**
     * Process default modifier with value
     */
    private function processDefaultModifier(string $modifier): string
    {
        $value = trim(str_replace('default', '', $modifier), ':');
        $value = trim($value);

        if (strtolower($value) === 'null') {
            return '->default(null)';
        }

        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return '->default(' . $value . ')';
        }

        if (is_numeric($value)) {
            return "->default($value)";
        }

        $value = trim($value, "'\"");

        return "->default('$value')";
    }

    /**
     * Build unique constraints for migration
     */
    private function buildUniqueConstraints(array $uniqueConstraints): string
    {
        $constraintStub = '';

        foreach ($uniqueConstraints as $columns) {
            if (is_array($columns)) {
                $cols = implode("', '", $columns);
                $constraintStub .= "\$table->unique(['$cols']);\n            ";
            } elseif (is_string($columns)) {
                $constraintStub .= "\$table->unique('$columns');\n            ";
            }
        }

        return $constraintStub;
    }

    /**
     * Update migration file with field definitions
     */
    private function updateMigrationFile(string $migrationFile, string $fieldStub): void
    {
        $migrationContent = file_get_contents($migrationFile);

        $migrationContent = preg_replace_callback(
            '/Schema::create\([^)]+function\s*\(Blueprint\s*\$table\)\s*{(.*?)(\$table->id\(\);)/s',
            function ($matches) use ($fieldStub) {
                return str_replace(
                    $matches[2],
                    $matches[2] . "\n            " . $fieldStub,
                    $matches[0]
                );
            },
            $migrationContent
        );

        file_put_contents($migrationFile, $migrationContent);
    }

    /**
     * Generate service class
     */
    protected function generateService(string $serviceClass, string $modelName, string $modelVar): void
    {
        $serviceDir = app_path('Services');
        $path = "{$serviceDir}/{$serviceClass}.php";
        $stubPath = $this->pathResolverService->resolveStubPath('service');

        if (!File::exists($stubPath)) {
            $this->error("Service stub not found: {$stubPath}");

            return;
        }

        File::ensureDirectoryExists($serviceDir);

        $stubContent = File::get($stubPath);
        $stubContent = str_replace(
            ['{{ model }}', '{{ variable }}'],
            [$modelName, $modelVar],
            $stubContent
        );

        File::put($path, $stubContent);
        $this->info("🤫 Service created: {$serviceClass}");
    }

    /**
     * Generate controller class
     */
    protected function generateController(string $controllerClass, string $modelName, string $modelVar, string $pluralModel): void
    {
        $path = app_path("Http/Controllers/{$controllerClass}.php");
        $stubPath = $this->pathResolverService->resolveStubPath('controller');

        File::ensureDirectoryExists(app_path('Http/Controllers'));
        File::put($path, str_replace(
            ['{{ class }}', '{{ model }}', '{{ variable }}', '{{ modelPlural }}', '{{ route }}'],
            [$controllerClass, $modelName, $modelVar, $pluralModel, Str::snake($modelName)],
            File::get($stubPath)
        ));

        $this->info("🤫 Controller created: $controllerClass");
    }
}
