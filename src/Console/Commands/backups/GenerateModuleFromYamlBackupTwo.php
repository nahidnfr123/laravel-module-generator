<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\backups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Yaml\Yaml;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\app_path;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\base_path;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\config;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\database_path;

class GenerateModuleFromYamlBackupTwo extends Command
{
    protected $signature = 'module:generate
                           {--force : Overwrite existing files}
                           {--file= : Path to a YAML file}
                           {--skip-postman : Skip Postman collection generation}
                           {--skip-dbdiagram : Skip DB diagram generation}
                           {--postman-base-url={{base-url}} : Base URL for Postman collection}
                           {--postman-prefix=api/v1 : API prefix for Postman collection}';

    protected $description = 'Generate Laravel module files (model, migration, controller, etc.) from a YAML file';

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
        $this->validateAndGetConfiguration();

        $models = $this->parseYamlFile();

        foreach ($models as $modelName => $modelData) {
            $this->processModel($modelName, $modelData);
        }

        $this->generateAdditionalFiles();

        $this->displaySuccessMessage();

        return CommandAlias::SUCCESS;
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
     * Delete existing model and migration files
     */
    private function deleteExistingModelFiles(string $modelPath, array $migrationFiles, string $modelName): void
    {
        if ($this->generateConfig['model']) {
            File::delete($modelPath);
            $this->warn("⚠️ Deleted existing model: {$modelName}");
        }

        if ($this->generateConfig['migration']) {
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
        $generateConfig = $this->generateConfig;
        $force = $this->option('force');

        if ($generateConfig['request']) {
            $this->handleRequestGeneration($modelConfig, $force);
        }

        if ($generateConfig['collection']) {
            $this->handleCollectionGeneration($modelConfig, $force);
        }

        if ($generateConfig['resource']) {
            $this->handleResourceGeneration($modelConfig, $force);
        }

        if ($generateConfig['service']) {
            $this->handleServiceGeneration($modelConfig, $force);
        }

        if ($generateConfig['controller']) {
            $this->handleControllerGeneration($modelConfig, $force);
            $this->appendRoute($modelConfig['tableName'], $modelConfig['classes']['controller']);
        }
    }

    /**
     * Handle request file generation
     */
    private function handleRequestGeneration(array $modelConfig, bool $force): void
    {
        $requestPath = app_path("Http/Requests/{$modelConfig['classes']['request']}.php");

        if (File::exists($requestPath) && !$force) {
            $this->warn("⚠️ Request already exists: {$modelConfig['classes']['request']}");

            return;
        }

        if ($this->generateConfig['request']) {
            if (File::exists($requestPath)) {
                File::delete($requestPath);
                $this->warn("⚠️ Deleted existing request: {$modelConfig['classes']['request']}");
            }

            $this->generateRequest($modelConfig['studlyName'], $modelConfig['fields']);
        }
    }

    /**
     * Handle collection file generation
     */
    private function handleCollectionGeneration(array $modelConfig, bool $force): void
    {
        $collectionPath = app_path("Http/Resources/{$modelConfig['studlyName']}/{$modelConfig['classes']['collection']}.php");

        if (File::exists($collectionPath) && !$force) {
            $this->warn("⚠️ Collection already exists: {$modelConfig['classes']['collection']}");

            return;
        }
        if ($this->generateConfig['collection']) {
            if (File::exists($collectionPath)) {
                File::delete($collectionPath);
                $this->warn("⚠️ Deleted existing collection: {$modelConfig['classes']['collection']}");
            }

            $this->generateCollection($modelConfig['studlyName'], $modelConfig['classes']['collection'], $modelConfig['camelName']);
        }
    }

    /**
     * Handle resource file generation
     */
    private function handleResourceGeneration(array $modelConfig, bool $force): void
    {
        $resourcePath = app_path("Http/Resources/{$modelConfig['studlyName']}/{$modelConfig['classes']['resource']}.php");

        if (File::exists($resourcePath) && !$force) {
            $this->warn("⚠️ Resource already exists: {$modelConfig['classes']['resource']}");

            return;
        }

        if ($this->generateConfig['resource']) {
            if (File::exists($resourcePath)) {
                File::delete($resourcePath);
                $this->warn("⚠️ Deleted existing resource: {$modelConfig['classes']['resource']}");
            }

            $this->call('make:resource', ['name' => "{$modelConfig['studlyName']}/{$modelConfig['classes']['resource']}"]);
        }
    }

    /**
     * Handle service file generation
     */
    private function handleServiceGeneration(array $modelConfig, bool $force): void
    {
        $servicePath = app_path("Services/{$modelConfig['classes']['service']}.php");

        if (File::exists($servicePath) && !$force) {
            $this->warn("⚠️ Service already exists: {$modelConfig['classes']['service']}");

            return;
        }
        if ($this->generateConfig['service']) {
            if (File::exists($servicePath)) {
                File::delete($servicePath);
                $this->warn("⚠️ Deleted existing service: {$modelConfig['classes']['service']}");
            }

            $this->generateService($modelConfig['classes']['service'], $modelConfig['studlyName'], $modelConfig['camelName']);
        }
    }

    /**
     * Handle controller file generation
     */
    private function handleControllerGeneration(array $modelConfig, bool $force): void
    {
        $controllerPath = app_path("Http/Controllers/{$modelConfig['classes']['controller']}.php");

        if (File::exists($controllerPath) && !$force) {
            $this->warn("⚠️ Controller already exists: {$modelConfig['classes']['controller']}");

            return;
        }

        if ($this->generateConfig['controller']) {
            if (File::exists($controllerPath)) {
                File::delete($controllerPath);
                $this->warn("⚠️ Deleted existing controller: {$modelConfig['classes']['controller']}");
            }

            $this->generateController(
                $modelConfig['classes']['controller'],
                $modelConfig['studlyName'],
                $modelConfig['camelName'],
                $modelConfig['pluralStudlyName']
            );
        }
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
     * Display final success message
     */
    private function displaySuccessMessage(): void
    {
        $this->newLine();
        $this->info('🎉 All modules generated successfully!');
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
     * Generate form request with validation rules
     */
    protected function generateRequest(string $modelName, array $fields): void
    {
        $requestClass = "{$modelName}Request";
        $requestPath = app_path("Http/Requests/{$requestClass}.php");
        $stubPath = $this->resolveStubPath('request');

        if (!File::exists($stubPath)) {
            $this->error("Request stub not found: {$stubPath}");

            return;
        }

        $rulesFormatted = $this->buildValidationRules($fields);
        $this->createRequestFile($stubPath, $requestPath, $modelName, $rulesFormatted);

        $this->info("🤫 Form Request created with validation: {$requestClass}");
    }

    /**
     * Build validation rules for request
     */
    private function buildValidationRules(array $fields): string
    {
        $rules = [];

        foreach ($fields as $name => $definition) {
            $rules[$name] = $this->generateFieldValidationRule($name, $definition);
        }

        return $this->formatValidationRules($rules);
    }

    /**
     * Generate validation rule for a single field
     */
    private function generateFieldValidationRule(string $name, string $definition): string
    {
        $parts = explode(':', $definition);
        $type = array_shift($parts);
        $isNullable = in_array('nullable', $parts);

        $ruleSet = [$isNullable ? 'nullable' : 'required'];

        switch ($type) {
            case 'string':
            case 'text':
                $ruleSet[] = 'string';
                break;
            case 'integer':
                $ruleSet[] = 'integer';
                break;
            case 'decimal':
                $ruleSet[] = 'numeric';
                break;
            case 'boolean':
                $ruleSet[] = 'boolean';
                break;
            case 'foreignId':
                $relatedTable = $parts[0] ?? Str::snake(Str::pluralStudly(Str::beforeLast($name, '_id')));
                $ruleSet[] = 'exists:' . $relatedTable . ',id';
                break;
        }

        return implode('|', $ruleSet);
    }

    /**
     * Format validation rules as string
     */
    private function formatValidationRules(array $rules): string
    {
        $rulesFormatted = '';
        foreach ($rules as $field => $rule) {
            $rulesFormatted .= "            '{$field}' => '{$rule}',\n";
        }

        return rtrim($rulesFormatted, "\n");
    }

    /**
     * Create request file from stub
     */
    private function createRequestFile(string $stubPath, string $requestPath, string $modelName, string $rulesFormatted): void
    {
        $stub = File::get($stubPath);
        $stub = str_replace(
            ['{{ model }}', '{{ rules }}'],
            [$modelName, $rulesFormatted],
            $stub
        );

        // Ensure the directory exists
        $directory = dirname($requestPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($requestPath, $stub);
    }

    /**
     * Generate service class
     */
    protected function generateService(string $serviceClass, string $modelName, string $modelVar): void
    {
        $serviceDir = app_path('Services');
        $path = "{$serviceDir}/{$serviceClass}.php";
        $stubPath = $this->resolveStubPath('service');

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
        $stubPath = $this->resolveStubPath('controller');

        File::ensureDirectoryExists(app_path('Http/Controllers'));
        File::put($path, str_replace(
            ['{{ class }}', '{{ model }}', '{{ variable }}', '{{ modelPlural }}', '{{ route }}'],
            [$controllerClass, $modelName, $modelVar, $pluralModel, Str::snake($modelName)],
            File::get($stubPath)
        ));

        $this->info("🤫 Controller created: $controllerClass");
    }

    /**
     * Generate resource collection class
     */
    protected function generateCollection(string $modelName, string $collectionClass, string $modelVar): void
    {
        $dir = app_path("Http/Resources/{$modelName}");
        $path = "{$dir}/{$collectionClass}.php";
        $stubPath = $this->resolveStubPath('collection');

        File::ensureDirectoryExists($dir);
        File::put($path, str_replace(
            ['{{model}}', '{{modelVar}}'],
            [$modelName, $modelVar],
            File::get($stubPath)
        ));

        $this->info('🤫 Collection created.');
    }

    /**
     * Append API route to routes file
     */
//    protected function appendRoute(string $tableName, string $controllerClass): void
//    {
//        $routeLine = "Route::apiResource('{$tableName}', \\App\\Http\\Controllers\\{$controllerClass}::class);";
//        $apiRoutesPath = base_path('routes/api.php');
//
//        if (!Str::contains(File::get($apiRoutesPath), $routeLine)) {
//            File::append($apiRoutesPath, "\n{$routeLine}\n");
//            $this->info('🤫 API route added.');
//        } else {
//            $this->warn("⚠️ Route Already Exists: {$routeLine}");
//        }
//    }
    protected function appendRoute(string $tableName, string $controllerClass): void
    {
        $routeLine = "Route::apiResource('{$tableName}', \\App\\Http\\Controllers\\{$controllerClass}::class);";
        $apiRoutesPath = base_path('routes/api.php');

        // Check if the api.php file exists, create it if it doesn't
        if (!File::exists($apiRoutesPath)) {
            // Create the routes directory if it doesn't exist
            $routesDirectory = dirname($apiRoutesPath);
            if (!File::exists($routesDirectory)) {
                File::makeDirectory($routesDirectory, 0755, true);
            }

            // Create a basic api.php file with the standard Laravel structure
            $defaultApiContent = "<?php\n\nuse Illuminate\Http\Request;\nuse Illuminate\Support\Facades\Route;\n\n/*\n|--------------------------------------------------------------------------\n| API Routes\n|--------------------------------------------------------------------------\n|\n| Here is where you can register API routes for your application. These\n| routes are loaded by the RouteServiceProvider and all of them will\n| be assigned to the \"api\" middleware group. Make something great!\n|\n*/\n\nRoute::get('/user', function (Request \$request) {\n    return \$request->user();\n})->middleware('auth:sanctum');\n";

            File::put($apiRoutesPath, $defaultApiContent);
            $this->info('📁 Created routes/api.php file.');
        }

        // Now check if the route already exists
        if (!Str::contains(File::get($apiRoutesPath), $routeLine)) {
            File::append($apiRoutesPath, "\n{$routeLine}\n");
            $this->info('🤫 API route added.');
        } else {
            $this->warn("⚠️ Route Already Exists: {$routeLine}");
        }
    }

    /**
     * Resolve the path to a stub file
     */
    protected function resolveStubPath(string $stubKey): string
    {
        $config = config('module-generator');
        if (!isset($config['stubs'], $config['base_path']) || !$config) {
            throw new \RuntimeException('Module generator stubs configuration not found.');
        }

        $stubFile = $config['stubs'][$stubKey] ?? null;

        if (!$stubFile) {
            throw new \InvalidArgumentException("Stub not defined for key: {$stubKey}");
        }

        // $publishedPath = base_path("module/stubs/{$stubFile}");
        $publishedPath = $config['base_path'] . "/stubs/{$stubFile}";

        if (file_exists($publishedPath)) {
            return $publishedPath;
        }

        $this->warn($publishedPath . ' stub path not found, using fallback path.');

        $fallbackPath = __DIR__ . '/../../stubs/' . $stubFile;

        if (!file_exists($fallbackPath)) {
            throw new \RuntimeException("Stub file not found at fallback path: {$fallbackPath}");
        }

        return $fallbackPath;
    }
}
