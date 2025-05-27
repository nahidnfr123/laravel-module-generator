<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Yaml\Yaml;

class GenerateModuleFromYaml extends Command
{
    protected $signature = 'module:generate 
                           {--force : Overwrite existing files} 
                           {--file= : Path to a YAML file}
                           {--skip-postman : Skip Postman collection generation}
                           {--skip-dbdiagram : Skip DB diagram generation}
                           {--postman-base-url={{base-url}} : Base URL for Postman collection}
                           {--postman-prefix=api/v1 : API prefix for Postman collection}';

    protected $description = 'Generate Laravel module files (model, migration, controller, etc.) from a YAML file';

    // ===========================================
    // MAIN COMMAND HANDLER
    // ===========================================

    public function handle()
    {
        $options = $this->parseCommandOptions();

        if (!$this->validateYamlFile($options['path'])) {
            return CommandAlias::FAILURE;
        }

        $models = Yaml::parseFile($options['path']);

        // Generate modules for each model
        foreach ($models as $modelName => $modelData) {
            $this->generateModuleForModel($modelName, $modelData, $options['force']);
        }

        // Generate additional components
        $this->generatePostmanCollection($options);
        $this->generateDbDiagram($options);

        $this->newLine();
        $this->info("🎉 All modules generated successfully!");

        return CommandAlias::SUCCESS;
    }

    // ===========================================
    // COMMAND OPTION PARSING
    // ===========================================

    private function parseCommandOptions(): array
    {
        $defaultPath = config('module-generator.models_path');

        return [
            'path' => $this->option('file') ?? $defaultPath,
            'force' => $this->option('force'),
            'skip_postman' => $this->option('skip-postman'),
            'skip_dbdiagram' => $this->option('skip-dbdiagram'),
            'postman_base_url' => $this->option('postman-base-url'),
            'postman_prefix' => $this->option('postman-prefix'),
        ];
    }

    private function validateYamlFile(string $path): bool
    {
        if (!file_exists($path)) {
            $this->error("YAML file not found at: $path");
            return false;
        }

        return true;
    }

    // ===========================================
    // MODULE GENERATION ORCHESTRATION
    // ===========================================

    private function generateModuleForModel(string $modelName, array $modelData, bool $force): void
    {
        $this->info("Generating files for: $modelName");

        $context = $this->prepareModelContext($modelName, $modelData);
        $generateConfig = $this->parseGenerationConfig($modelData['generate'] ?? true);

        // Generate core files (Model & Migration - always generated)
        $this->generateCoreFiles($context, $force);

        // Generate optional components based on configuration
        $this->generateOptionalComponents($context, $generateConfig, $force);

        $this->info("🤫 Module generated for {$context['model_name']}");
        sleep(1);
    }

    private function prepareModelContext(string $modelName, array $modelData): array
    {
        $modelName = Str::studly($modelName);
        $modelVar = Str::camel($modelName);
        $pluralModel = Str::pluralStudly($modelName);
        $tableName = Str::snake(Str::plural($modelName));

        return [
            'model_name' => $modelName,
            'model_var' => $modelVar,
            'plural_model' => $pluralModel,
            'table_name' => $tableName,
            'fields' => $modelData['fields'] ?? [],
            'relations' => $modelData['relations'] ?? [],
            'controller_class' => "{$modelName}Controller",
            'service_class' => "{$modelName}Service",
            'collection_class' => "{$modelName}Collection",
            'resource_class' => "{$modelName}Resource",
            'request_class' => "{$modelName}Request",
        ];
    }

    private function parseGenerationConfig($generate): array
    {
        $defaultComponents = ['controller', 'service', 'request', 'resource', 'collection'];

        if ($generate === false) {
            return array_fill_keys($defaultComponents, false);
        }

        if ($generate === true) {
            return array_fill_keys($defaultComponents, true);
        }

        // Merge with defaults to ensure all keys exist
        return array_merge(
            array_fill_keys($defaultComponents, true),
            $generate
        );
    }

    // ===========================================
    // CORE FILE GENERATION (Model & Migration)
    // ===========================================

    private function generateCoreFiles(array $context, bool $force): void
    {
        $modelPath = app_path("Models/{$context['model_name']}.php");
        $migrationPattern = database_path("migrations/*create_{$context['table_name']}_table.php");
        $migrationFiles = glob($migrationPattern);

        if ($this->shouldSkipGeneration($modelPath, $force, "Model already exists: {$context['model_name']}")) {
            return;
        }

        // Clean up existing files if force is enabled
        if (File::exists($modelPath)) {
            File::delete($modelPath);
            $this->cleanupMigrationFiles($migrationFiles);
            $this->warn("⚠️ Deleted existing model: {$context['model_name']}");
        }

        $this->generateModel($context['model_name'], $context['fields'], $context['relations']);
        $this->generateMigration($context['model_name'], $context['fields']);
    }

    private function cleanupMigrationFiles(array $migrationFiles): void
    {
        foreach ($migrationFiles as $file) {
            File::delete($file);
            $this->warn('⚠️ Deleted existing migration: ' . basename($file));
        }
    }

    // ===========================================
    // OPTIONAL COMPONENT GENERATION
    // ===========================================

    private function generateOptionalComponents(array $context, array $generateConfig, bool $force): void
    {
        if ($generateConfig['request']) {
            $this->generateRequestComponent($context, $force);
        }

        if ($generateConfig['collection']) {
            $this->generateCollectionComponent($context, $force);
        }

        if ($generateConfig['resource']) {
            $this->generateResourceComponent($context, $force);
        }

        if ($generateConfig['service']) {
            $this->generateServiceComponent($context, $force);
        }

        if ($generateConfig['controller']) {
            $this->generateControllerComponent($context, $force);
        }
    }

    private function generateRequestComponent(array $context, bool $force): void
    {
        $requestPath = app_path("Http/Requests/{$context['request_class']}.php");

        if ($this->shouldSkipGeneration($requestPath, $force, "Request already exists: {$context['request_class']}")) {
            return;
        }

        File::delete($requestPath);
        $this->warn("⚠️ Deleted existing request: {$context['request_class']}");
        $this->generateRequest($context['model_name'], $context['fields']);
    }

    private function generateCollectionComponent(array $context, bool $force): void
    {
        $collectionPath = app_path("Http/Resources/{$context['model_name']}/{$context['collection_class']}.php");

        if ($this->shouldSkipGeneration($collectionPath, $force, "Collection already exists: {$context['collection_class']}")) {
            return;
        }

        File::delete($collectionPath);
        $this->warn("⚠️ Deleted existing collection: {$context['collection_class']}");
        $this->generateCollection($context['model_name'], $context['collection_class'], $context['model_var']);
    }

    private function generateResourceComponent(array $context, bool $force): void
    {
        $resourcePath = app_path("Http/Resources/{$context['model_name']}/{$context['resource_class']}.php");

        if ($this->shouldSkipGeneration($resourcePath, $force, "Resource already exists: {$context['resource_class']}")) {
            return;
        }

        File::delete($resourcePath);
        $this->warn("⚠️ Deleted existing resource: {$context['resource_class']}");
        $this->call('make:resource', ['name' => "{$context['model_name']}/{$context['resource_class']}"]);
    }

    private function generateServiceComponent(array $context, bool $force): void
    {
        $servicePath = app_path("Services/{$context['service_class']}.php");

        if ($this->shouldSkipGeneration($servicePath, $force, "Service already exists: {$context['service_class']}")) {
            return;
        }

        File::delete($servicePath);
        $this->warn("⚠️ Deleted existing service: {$context['service_class']}");
        $this->generateService($context['service_class'], $context['model_name'], $context['model_var']);
    }

    private function generateControllerComponent(array $context, bool $force): void
    {
        $controllerPath = app_path("Http/Controllers/{$context['controller_class']}.php");

        if ($this->shouldSkipGeneration($controllerPath, $force, "Controller already exists: {$context['controller_class']}")) {
            // Still append route even if controller exists
            $this->appendRoute($context['table_name'], $context['controller_class']);
            return;
        }

        File::delete($controllerPath);
        $this->warn("⚠️ Deleted existing controller: {$context['controller_class']}");
        $this->generateController(
            $context['controller_class'],
            $context['model_name'],
            $context['model_var'],
            $context['plural_model']
        );

        // Append route when controller is generated
        $this->appendRoute($context['table_name'], $context['controller_class']);
    }

    private function shouldSkipGeneration(string $filePath, bool $force, string $warningMessage): bool
    {
        if (File::exists($filePath) && !$force) {
            $this->warn("⚠️ {$warningMessage}");
            return true;
        }

        return false;
    }

    // ===========================================
    // ADDITIONAL COMPONENT GENERATION
    // ===========================================

    private function generatePostmanCollection(array $options): void
    {
        if ($options['skip_postman']) {
            return;
        }

        $this->newLine();
        $this->info("🚀 Generating Postman collection...");

        $result = $this->call('postman:generate', [
            '--file' => $options['path'],
            '--base-url' => $options['postman_base_url'],
            '--prefix' => $options['postman_prefix']
        ]);

        if ($result === CommandAlias::SUCCESS) {
            $this->newLine();
            $this->info("🥵 Postman collection generated successfully!");
        } else {
            $this->warn("⚠️ Failed to generate Postman collection");
        }
    }

    private function generateDbDiagram(array $options): void
    {
        if ($options['skip_dbdiagram']) {
            return;
        }

        $this->newLine();
        $this->info("🚀 Generating DB diagram...");

        $result = $this->call('dbdiagram:generate', [
            '--file' => $options['path'],
            '--output' => 'module/dbdiagram.dbml',
        ]);

        if ($result === CommandAlias::SUCCESS) {
            $this->newLine();
            $this->info("🤧 DB diagram generated successfully at module/dbdiagram.dbml");
        } else {
            $this->warn("⚠️ Failed to generate DB diagram");
        }
    }

    // ===========================================
    // FILE GENERATION METHODS
    // ===========================================

    protected function generateModel(string $modelName, array $fields, array $relations = []): void
    {
        Artisan::call('make:model', ['name' => $modelName, '--migration' => true]);

        $modelPath = app_path("Models/{$modelName}.php");
        if (!File::exists($modelPath)) {
            $this->warn("⚠️ Model file not found for: {$modelName}");
            return;
        }

        $this->addFillableFields($modelPath, $modelName, $fields);
        $this->addRelationshipMethods($modelPath, $relations);

        $this->info("🤫 Fillable fields and relationships added to {$modelName} model");
    }

    private function addFillableFields(string $modelPath, string $modelName, array $fields): void
    {
        $fillableFields = array_map(fn($field) => "        '{$field}'", array_keys($fields));
        $fillableArray = "protected \$fillable = [\n" . implode(",\n", $fillableFields) . ",\n    ];";

        $modelContent = File::get($modelPath);
        $modelContent = preg_replace(
            '/(class\s+' . $modelName . '\s+extends\s+Model\s*\{)/',
            "$1\n\n    {$fillableArray}\n",
            $modelContent
        );

        File::put($modelPath, $modelContent);
    }

    private function addRelationshipMethods(string $modelPath, array $relations): void
    {
        if (empty($relations)) {
            return;
        }

        $relationshipMethods = $this->buildRelationshipMethods($relations);

        $modelContent = File::get($modelPath);
        $modelContent = str_replace(
            'protected $fillable = [',
            $relationshipMethods . "\n\n    protected \$fillable = [",
            $modelContent
        );

        File::put($modelPath, $modelContent);
    }

    private function buildRelationshipMethods(array $relations): string
    {
        $methods = '';

        foreach ($relations as $relationName => $meta) {
            $relationName = Str::camel($relationName);
            $type = $meta['type'];
            $relatedModel = $meta['model'];

            $methods .= <<<PHP


    public function {$relationName}()
    {
        return \$this->{$type}({$relatedModel}::class);
    }
PHP;
        }

        return $methods;
    }

    protected function generateMigration(string $modelName, array $fields, array $uniqueConstraints = []): void
    {
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $migrationFiles = glob(database_path('migrations/*create_' . $tableName . '_table.php'));

        if (empty($migrationFiles)) {
            $this->warn("Migration file not found for $modelName.");
            return;
        }

        $migrationFile = $migrationFiles[0];
        $this->updateMigrationFile($migrationFile, $fields, $uniqueConstraints);

        $this->info("✅ Migration file updated for $modelName");
    }

    private function updateMigrationFile(string $migrationFile, array $fields, array $uniqueConstraints): void
    {
        $migrationContent = file_get_contents($migrationFile);
        $fieldDefinitions = $this->buildFieldDefinitions($fields, $uniqueConstraints);

        $migrationContent = preg_replace_callback(
            '/Schema::create\([^)]+function\s*\(Blueprint\s*\$table\)\s*{(.*?)(\$table->id\(\);)/s',
            function ($matches) use ($fieldDefinitions) {
                return str_replace(
                    $matches[2],
                    $matches[2] . "\n            " . $fieldDefinitions,
                    $matches[0]
                );
            },
            $migrationContent
        );

        file_put_contents($migrationFile, $migrationContent);
    }

    private function buildFieldDefinitions(array $fields, array $uniqueConstraints): string
    {
        $fieldStub = '';

        foreach ($fields as $name => $definition) {
            $fieldStub .= $this->buildSingleFieldDefinition($name, $definition) . ";\n            ";
        }

        $fieldStub .= $this->buildUniqueConstraints($uniqueConstraints);

        return $fieldStub;
    }

    private function buildSingleFieldDefinition(string $name, string $definition): string
    {
        $parts = explode(':', $definition);
        $type = array_shift($parts);

        if ($type === 'foreignId') {
            return $this->buildForeignKeyDefinition($name, $parts);
        }

        return $this->buildRegularFieldDefinition($name, $type, $parts);
    }

    private function buildForeignKeyDefinition(string $name, array $parts): string
    {
        $references = array_shift($parts);
        $line = "\$table->foreignId('$name')";

        // Add modifiers
        foreach ($parts as $modifier) {
            if (str_starts_with($modifier, 'default(')) {
                $line .= "->{$modifier}";
            } else {
                $line .= "->$modifier()";
            }
        }

        return $line . "->constrained('$references')->cascadeOnDelete()";
    }

    private function buildRegularFieldDefinition(string $name, string $type, array $parts): string
    {
        $line = "\$table->$type('$name')";

        foreach ($parts as $modifier) {
            $line .= $this->processFieldModifier($modifier);
        }

        return $line;
    }

    private function processFieldModifier(string $modifier): string
    {
        if (str_starts_with($modifier, 'default(')) {
            return "->{$modifier}";
        }

        if (str_starts_with($modifier, 'default')) {
            return $this->processDefaultValue($modifier);
        }

        return "->$modifier()";
    }

    private function processDefaultValue(string $modifier): string
    {
        $value = trim(str_replace('default', '', $modifier), ':');
        $value = trim($value);

        if (strtolower($value) === 'null') {
            return '->default(null)';
        }

        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return "->default($value)";
        }

        if (is_numeric($value)) {
            return "->default($value)";
        }

        $value = trim($value, "'\"");
        return "->default('$value')";
    }

    private function buildUniqueConstraints(array $uniqueConstraints): string
    {
        $constraints = '';

        foreach ($uniqueConstraints as $columns) {
            if (is_array($columns)) {
                $cols = implode("', '", $columns);
                $constraints .= "\$table->unique(['$cols']);\n            ";
            } elseif (is_string($columns)) {
                $constraints .= "\$table->unique('$columns');\n            ";
            }
        }

        return $constraints;
    }

    protected function generateRequest(string $modelName, array $fields): void
    {
        $requestClass = "{$modelName}Request";
        $requestPath = app_path("Http/Requests/{$requestClass}.php");
        $stubPath = $this->resolveStubPath('request');

        if (!File::exists($stubPath)) {
            $this->error("Request stub not found: {$stubPath}");
            return;
        }

        $validationRules = $this->buildValidationRules($fields);
        $stub = $this->processRequestStub($stubPath, $modelName, $validationRules);

        File::put($requestPath, $stub);
        $this->info("🤫 Form Request created with validation: {$requestClass}");
    }

    private function buildValidationRules(array $fields): string
    {
        $rules = [];

        foreach ($fields as $name => $definition) {
            $rules[$name] = $this->buildFieldValidationRule($name, $definition);
        }

        $rulesFormatted = '';
        foreach ($rules as $field => $rule) {
            $rulesFormatted .= "            '{$field}' => '{$rule}',\n";
        }

        return rtrim($rulesFormatted, "\n");
    }

    private function buildFieldValidationRule(string $name, string $definition): string
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

    private function processRequestStub(string $stubPath, string $modelName, string $validationRules): string
    {
        $stub = File::get($stubPath);

        return str_replace(
            ['{{ model }}', '{{ rules }}'],
            [$modelName, $validationRules],
            $stub
        );
    }

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

    protected function generateController(string $controllerClass, string $modelName, string $modelVar, string $pluralModel): void
    {
        $path = app_path("Http/Controllers/{$controllerClass}.php");
        $stubPath = $this->resolveStubPath('controller');

        File::ensureDirectoryExists(app_path('Http/Controllers'));

        $stubContent = File::get($stubPath);
        $stubContent = str_replace(
            ['{{ class }}', '{{ model }}', '{{ variable }}', '{{ modelPlural }}', '{{ route }}'],
            [$controllerClass, $modelName, $modelVar, $pluralModel, Str::snake($modelName)],
            $stubContent
        );

        File::put($path, $stubContent);
        $this->info("🤫 Controller created: $controllerClass");
    }

    protected function generateCollection(string $modelName, string $collectionClass, string $modelVar): void
    {
        $dir = app_path("Http/Resources/{$modelName}");
        $path = "{$dir}/{$collectionClass}.php";
        $stubPath = $this->resolveStubPath('collection');

        File::ensureDirectoryExists($dir);

        $stubContent = File::get($stubPath);
        $stubContent = str_replace(
            ['{{model}}', '{{modelVar}}'],
            [$modelName, $modelVar],
            $stubContent
        );

        File::put($path, $stubContent);
        $this->info('🤫 Collection created.');
    }

    protected function appendRoute(string $tableName, string $controllerClass): void
    {
        $routeLine = "Route::apiResource('{$tableName}', \\App\\Http\\Controllers\\{$controllerClass}::class);";
        $apiRoutesPath = base_path('routes/api.php');

        if (!Str::contains(File::get($apiRoutesPath), $routeLine)) {
            File::append($apiRoutesPath, "\n{$routeLine}\n");
            $this->info('🤫 API route added.');
        } else {
            $this->warn("⚠️ Route Already Exists: {$routeLine}");
        }
    }

    // ===========================================
    // UTILITY METHODS
    // ===========================================

    protected function resolveStubPath(string $stubKey): string
    {
        $config = config('module-generator');
        if (!$config || !isset($config['stubs'])) {
            throw new \RuntimeException('Module generator stubs configuration not found.');
        }

        $stubFile = $config['stubs'][$stubKey] ?? null;

        if (!$stubFile) {
            throw new \InvalidArgumentException("Stub not defined for key: {$stubKey}");
        }

        $publishedPath = base_path("module/stub/{$stubFile}");

        if (file_exists($publishedPath)) {
            return $publishedPath;
        }

        $fallbackPath = __DIR__ . '/../../stubs/' . $stubFile;

        if (!file_exists($fallbackPath)) {
            throw new \RuntimeException("Stub file not found at fallback path: {$fallbackPath}");
        }

        return $fallbackPath;
    }
}