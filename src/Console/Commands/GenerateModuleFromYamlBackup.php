<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Yaml\Yaml;

class GenerateModuleFromYamlBackup extends Command
{
    protected $signature = 'module:generate 
                           {--force : Overwrite existing files} 
                           {--file= : Path to a YAML file}
                           {--skip-postman : Skip Postman collection generation}
                           {--skip-dbdiagram : Skip DB diagram generation}
                           {--postman-base-url={{base-url}} : Base URL for Postman collection}
                           {--postman-prefix=api/v1 : API prefix for Postman collection}';

    protected $description = 'Generate Laravel module files (model, migration, controller, etc.) from a YAML file';

    public function handle()
    {
        $defaultPath = config('module-generator.models_path');
        $path = $this->option('file') ?? $defaultPath;
        $force = $this->option('force');
        $skipPostman = $this->option('skip-postman');
        $dbDiagram = $this->option('skip-dbdiagram');

        if (!file_exists($path)) {
            $this->error("YAML file not found at: $path");
            return CommandAlias::FAILURE;
        }

        $models = Yaml::parseFile($path);

        foreach ($models as $modelName => $modelData) {
            $this->info("Generating files for: $modelName");

            $fields = $modelData['fields'] ?? [];
            $relations = $modelData['relations'] ?? [];

            $modelName = Str::studly($modelName);
            $modelVar = Str::camel($modelName);
            $pluralModel = Str::pluralStudly($modelName);
            $tableName = Str::snake(Str::plural($modelName));

            $controllerClass = "{$modelName}Controller";
            $serviceClass = "{$modelName}Service";
            $collectionClass = "{$modelName}Collection";
            $resourceClass = "{$modelName}Resource";
            $requestClass = "{$modelName}Request";

            // Determine what to generate
            $generate = $modelData['generate'] ?? true;
            if ($generate === false) {
                $generate = array_fill_keys(['controller', 'service', 'request', 'resource', 'collection'], false);
            } elseif ($generate === true) {
                $generate = array_fill_keys(['controller', 'service', 'request', 'resource', 'collection'], true);
            } else {
                // Normalize: ensure all keys are set
                $generate = array_merge([
                    'controller' => true,
                    'service' => true,
                    'request' => true,
                    'resource' => true,
                    'collection' => true,
                ], $generate);
            }

            // 1: Model & Migration
            $modelPath = app_path("Models/{$modelName}.php");
            $migrationPattern = database_path("migrations/*create_{$tableName}_table.php");
            $migrationFiles = glob($migrationPattern);

            if (File::exists($modelPath) && !$force) {
                $this->warn("⚠️ Model already exists: {$modelName}");
            } else {
                if (File::exists($modelPath)) {
                    File::delete($modelPath);
                    foreach ($migrationFiles as $file) {
                        File::delete($file);
                        $this->warn('⚠️ Deleted existing migration: ' . basename($file));
                    }
                    $this->warn("⚠️ Deleted existing model: {$modelName}");
                }

                $this->generateModel($modelName, $fields, $relations);

                // Migration is always generated
                $uniqueConstraints = [];
                $this->generateMigration($modelName, $fields, $uniqueConstraints);
            }

            // 2: Request
            if ($generate['request']) {
                $requestPath = app_path("Http/Requests/{$requestClass}.php");
                if (File::exists($requestPath) && !$force) {
                    $this->warn("⚠️ Request already exists: {$requestClass}");
                } else {
                    File::delete($requestPath);
                    $this->warn("⚠️ Deleted existing request: {$requestClass}");
                    $this->generateRequest($modelName, $fields);
                }
            }

            // 3: Collection
            if ($generate['collection']) {
                $collectionPath = app_path("Http/Resources/{$modelName}/{$collectionClass}.php");
                if (File::exists($collectionPath) && !$force) {
                    $this->warn("⚠️ Collection already exists: {$collectionClass}");
                } else {
                    File::delete($collectionPath);
                    $this->warn("⚠️ Deleted existing collection: {$collectionClass}");
                    $this->generateCollection($modelName, $collectionClass, $modelVar);
                }
            }

            // 4: Resource
            if ($generate['resource']) {
                $resourcePath = app_path("Http/Resources/{$modelName}/{$resourceClass}.php");
                if (File::exists($resourcePath) && !$force) {
                    $this->warn("⚠️ Resource already exists: {$resourceClass}");
                } else {
                    File::delete($resourcePath);
                    $this->warn("⚠️ Deleted existing resource: {$resourceClass}");
                    $this->call('make:resource', ['name' => "{$modelName}/{$resourceClass}"]);
                }
            }

            // 5: Service
            if ($generate['service']) {
                $servicePath = app_path("Services/{$serviceClass}.php");
                if (File::exists($servicePath) && !$force) {
                    $this->warn("⚠️ Service already exists: {$serviceClass}");
                } else {
                    File::delete($servicePath);
                    $this->warn("⚠️ Deleted existing service: {$serviceClass}");
                    $this->generateService($serviceClass, $modelName, $modelVar);
                }
            }

            // 6: Controller
            if ($generate['controller']) {
                $controllerPath = app_path("Http/Controllers/{$controllerClass}.php");
                if (File::exists($controllerPath) && !$force) {
                    $this->warn("⚠️ Controller already exists: {$controllerClass}");
                } else {
                    File::delete($controllerPath);
                    $this->warn("⚠️ Deleted existing controller: {$controllerClass}");
                    $this->generateController($controllerClass, $modelName, $modelVar, $pluralModel);
                }

                // Append route only if controller exists
                $this->appendRoute($tableName, $controllerClass);
            }

            $this->info("🤫 Module generated for $modelName");
            sleep(1);
        }

        // Generate Postman Collection at the end (unless skipped)
        if (!$skipPostman) {
            $this->newLine();
            $this->info("🚀 Generating Postman collection...");

            $baseUrl = $this->option('postman-base-url');
            $prefix = $this->option('postman-prefix');

            $result = $this->call('postman:generate', [
                '--file' => $path,
                '--base-url' => $baseUrl,
                '--prefix' => $prefix
            ]);

            if ($result === CommandAlias::SUCCESS) {
                $this->newLine();
                $this->info("🥵 Postman collection generated successfully!");
            } else {
                $this->warn("⚠️ Failed to generate Postman collection");
            }
        }

        // Generate DB diagram if not skipped
        if (!$dbDiagram) {
            $this->newLine();
            $this->info("🚀 Generating DB diagram...");

            $result = $this->call('dbdiagram:generate', [
                '--file' => $path,
                '--output' => 'module/dbdiagram.dbml',
            ]);

            if ($result === CommandAlias::SUCCESS) {
                $this->newLine();
                $this->info("🤧 DB diagram generated successfully at module/dbdiagram.dbml");
            } else {
                $this->warn("⚠️ Failed to generate DB diagram");
            }
        }

        $this->newLine();
        $this->info("🎉 All modules generated successfully!");

        return CommandAlias::SUCCESS;
    }

    protected function generateModel(string $modelName, array $fields, array $relations = []): void
    {
        Artisan::call('make:model', ['name' => $modelName, '--migration' => true]);

        $modelPath = app_path("Models/{$modelName}.php");
        if (!File::exists($modelPath)) {
            $this->warn("⚠️ Model file not found for: {$modelName}");
            return;
        }

        // Fillable properties
        $fillableFields = array_map(fn($field) => "        '{$field}'", array_keys($fields));
        $fillableArray = "protected \$fillable = [\n" . implode(",\n", $fillableFields) . ",\n    ];";

        // Relationships
        $relationshipMethods = '';
        if ($relations && count($relations)) {
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
        }
        $modelContent = File::get($modelPath);

        // Insert fillable and relationships after class declaration
        $modelContent = preg_replace(
            '/(class\s+' . $modelName . '\s+extends\s+Model\s*\{)/',
            "$1\n\n    {$fillableArray}\n{$relationshipMethods}\n",
            $modelContent
        );

        File::put($modelPath, $modelContent);
        $this->info("🤫 Fillable fields and relationships added to {$modelName} model");
    }

    protected function generateMigration(string $modelName, array $fields, array $uniqueConstraints = []): void
    {
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $files = glob(database_path('migrations/*create_' . $tableName . '_table.php'));

        if (empty($files)) {
            $this->warn("Migration file not found for $modelName.");
            return;
        }

        $migrationFile = $files[0];
        $migrationContent = file_get_contents($migrationFile);

        $fieldStub = '';

        foreach ($fields as $name => $definition) {
            $parts = explode(':', $definition);
            $type = array_shift($parts);

            $modifiers = [];
            $references = null;

            if ($type === 'foreignId') {
                $references = array_shift($parts);

                foreach ($parts as $modifier) {
                    $modifiers[] = $modifier;
                }

                $line = "\$table->{$type}('$name')";

                foreach ($modifiers as $modifier) {
                    if (str_starts_with($modifier, 'default(')) {
                        $line .= "->{$modifier}";
                    } else {
                        $line .= "->$modifier()";
                    }
                }

                $line .= "->constrained('$references')->cascadeOnDelete()";
            } else {
                $line = "\$table->$type('$name')";

                foreach ($parts as $modifier) {
                    if (str_starts_with($modifier, 'default(')) {
                        $line .= "->{$modifier}";
                    } elseif (str_starts_with($modifier, 'default')) {
                        $value = trim(str_replace('default', '', $modifier), ':');
                        $value = trim($value);

                        if (strtolower($value) === 'null') {
                            $line .= '->default(null)';
                        } elseif (in_array(strtolower($value), ['true', 'false'], true)) {
                            $line .= '->default(' . $value . ')';
                        } elseif (is_numeric($value)) {
                            $line .= "->default($value)";
                        } else {
                            $value = trim($value, "'\"");
                            $line .= "->default('$value')";
                        }
                    } elseif ($modifier === 'nullable') {
                        $line .= '->nullable()';
                    } elseif ($modifier === 'unique') {
                        $line .= '->unique()';
                    }
                }
            }

            $fieldStub .= $line . ";\n            ";
        }

        if (!empty($uniqueConstraints)) {
            foreach ($uniqueConstraints as $columns) {
                if (is_array($columns)) {
                    $cols = implode("', '", $columns);
                    $fieldStub .= "\$table->unique(['$cols']);\n            ";
                } elseif (is_string($columns)) {
                    $fieldStub .= "\$table->unique('$columns');\n            ";
                }
            }
        }

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
        $this->info("✅ Migration file updated for $modelName");
    }

    protected function parseModifiers(array $parts): array
    {
        $modifiers = [];

        foreach ($parts as $i => $iValue) {
            if ($iValue === 'default' && isset($parts[$i + 1])) {
                $modifiers[] = 'default:' . $parts[++$i];
            } else {
                $modifiers[] = $iValue;
            }
        }

        return $modifiers;
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

        // Generate validation rules
        $rules = [];

        foreach ($fields as $name => $definition) {
            $parts = explode(':', $definition);
            $type = array_shift($parts);

            $isNullable = in_array('nullable', $parts);
            $ruleSet = [];

            $ruleSet[] = $isNullable ? 'nullable' : 'required';

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

            $rules[$name] = implode('|', $ruleSet);
        }

        // Format rules as string
        $rulesFormatted = '';
        foreach ($rules as $field => $rule) {
            $rulesFormatted .= "            '{$field}' => '{$rule}',\n";
        }
        $rulesFormatted = rtrim($rulesFormatted, "\n");

        // Replace placeholders in stub
        $stub = File::get($stubPath);
        $stub = str_replace(
            ['{{ model }}', '{{ rules }}'],
            [$modelName, $rulesFormatted],
            $stub
        );

        File::put($requestPath, $stub);
        $this->info("🤫 Form Request created with validation: {$requestClass}");
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
        File::put($path, str_replace(
            ['{{ class }}', '{{ model }}', '{{ variable }}', '{{ modelPlural }}', '{{ route }}'],
            [$controllerClass, $modelName, $modelVar, $pluralModel, Str::snake($modelName)],
            File::get($stubPath)
        ));
        $this->info("🤫 Controller created: $controllerClass");
    }

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