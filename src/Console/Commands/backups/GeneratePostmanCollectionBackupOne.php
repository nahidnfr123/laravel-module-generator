<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\backups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

use function NahidFerdous\LaravelModuleGenerator\Console\Commands\config;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\env;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\now;

class GeneratePostmanCollectionBackupOne extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postman:generate
                           {--file= : Path to the YAML schema file}
                           {--base-url= : Base URL for API}
                           {--prefix= : API prefix}
                           {--output= : Output file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Postman collection from YAML schema';

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $apiPrefix;

    /**
     * @var array
     */
    private $collection;

    /**
     * @var array
     */
    private $fullSchema;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Get config values
        $config = config('module-generator');

        // Resolve options with config fallbacks
        $yamlFile = $this->option('file') ?: $config['models_path'];
        $this->baseUrl = rtrim($this->option('base-url') ?: $config['postman']['default_base_url'], '/');
        $this->apiPrefix = trim($this->option('prefix') ?: $config['postman']['default_prefix'], '/');

        // Handle output file
        $outputFile = $this->option('output');
        if (! $outputFile) {
            $configOutput = $config['postman']['output_path'] ?? null;
            if ($configOutput) {
                $outputFile = $configOutput;
            } else {
                $randomNumber = rand(100, 999);
                $outputFile = "module/postman_collection_{$randomNumber}.json";
            }
        }

        $this->ensureModuleDirectoryExists();

        if (! File::exists($yamlFile)) {
            $this->error("YAML file not found: {$yamlFile}");

            return self::FAILURE;
        }

        $this->info("Parsing YAML schema from: {$yamlFile}");
        $this->info("Base URL: {$this->baseUrl}");
        $this->info("API Prefix: {$this->apiPrefix}");

        try {
            $this->fullSchema = Yaml::parseFile($yamlFile);
            $this->collection = $this->initializeCollection();

            foreach ($this->fullSchema as $modelName => $modelConfig) {
                if ($this->shouldGenerateController($modelConfig) && ! $this->hasRequestParent($modelConfig)) {
                    $this->generateModelEndpoints($modelName, $modelConfig);
                }
            }

            $this->savePostmanCollection($outputFile);

        } catch (\Exception $e) {
            $this->error('Error generating collection: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Ensures the 'module' directory exists.
     */
    private function ensureModuleDirectoryExists(): void
    {
        if (! File::exists('module')) {
            File::makeDirectory('module', 0755, true);
        }
    }

    /**
     * Initializes the base Postman collection structure.
     */
    private function initializeCollection(): array
    {
        return [
            'info' => [
                'name' => env('APP_NAME', 'Laravel').' API Collection',
                'description' => 'Auto-generated from YAML schema',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                '_postman_id' => Str::uuid()->toString(),
            ],
            'item' => [],
            'variable' => [
                [
                    'key' => 'baseUrl',
                    'value' => $this->baseUrl,
                    'type' => 'string',
                ],
                [
                    'key' => 'token',
                    'value' => '',
                    'type' => 'string',
                ],
            ],
        ];
    }

    /**
     * Checks if the controller generation is enabled for the model.
     */
    private function shouldGenerateController(array $modelConfig): bool
    {
        return ! isset($modelConfig['generate']['controller']) || $modelConfig['generate']['controller'] !== false;
    }

    /**
     * Checks if the model has a requestParent defined.
     */
    private function hasRequestParent(array $modelConfig): bool
    {
        return isset($modelConfig['requestParent']);
    }

    /**
     * Gets nested models that belong to the given parent model (recursive).
     */
    private function getNestedModels(string $parentModelName): array
    {
        $nestedModels = [];

        foreach ($this->fullSchema as $modelName => $modelConfig) {
            if (isset($modelConfig['requestParent']) && $modelConfig['requestParent'] === $parentModelName) {
                $nestedModels[$modelName] = $modelConfig;

                // Recursively get nested models of this nested model
                $deeperNested = $this->getNestedModels($modelName);
                if (! empty($deeperNested)) {
                    $nestedModels[$modelName]['_nested'] = $deeperNested;
                }
            }
        }

        return $nestedModels;
    }

    /**
     * Generates the Postman endpoints for a given model.
     */
    private function generateModelEndpoints(string $modelName, array $modelConfig): void
    {
        $resourceName = Str::kebab(Str::plural($modelName));

        $modelFolder = [
            'name' => Str::plural($modelName),
            'item' => [],
        ];

        // Get nested models for this parent
        $nestedModels = $this->getNestedModels($modelName);

        // List Resources
        $modelFolder['item'][] = $this->createRequest(
            'Get All '.Str::plural($modelName),
            'GET',
            "{$this->apiPrefix}/{$resourceName}",
            null,
            $this->generateListResponse($modelName, $modelConfig, $nestedModels)
        );

        // Show Resource
        $modelFolder['item'][] = $this->createRequest(
            'Get '.$modelName.' by ID',
            'GET',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            null,
            $this->generateShowResponse($modelName, $modelConfig, $nestedModels)
        );

        // Store Resource
        $modelFolder['item'][] = $this->createRequest(
            'Create '.$modelName,
            'POST',
            "{$this->apiPrefix}/{$resourceName}",
            $this->generateCreateBody($modelConfig, $nestedModels),
            $this->generateCreateResponse($modelName, $modelConfig, $nestedModels)
        );

        // Update Resource
        $modelFolder['item'][] = $this->createRequest(
            'Update '.$modelName,
            'PUT',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            $this->generateUpdateBody($modelConfig, $nestedModels),
            $this->generateUpdateResponse($modelName, $modelConfig, $nestedModels)
        );

        // Delete Resource
        $modelFolder['item'][] = $this->createRequest(
            'Delete '.$modelName,
            'DELETE',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            null,
            $this->generateDeleteResponse()
        );

        $this->collection['item'][] = $modelFolder;
    }

    /**
     * Creates a single Postman request item.
     */
    private function createRequest(string $name, string $method, string $url, ?array $body = null, ?array $exampleResponse = null): array
    {
        $request = [
            'name' => $name,
            'request' => [
                'method' => $method,
                'header' => [
                    [
                        'key' => 'Accept',
                        'value' => 'application/json',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'Content-Type',
                        'value' => 'application/json',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'Authorization',
                        'value' => 'Bearer {{token}}',
                        'type' => 'text',
                    ],
                ],
                'url' => [
                    'raw' => '{{baseUrl}}/'.$url,
                    'host' => ['{{baseUrl}}'],
                    'path' => explode('/', $url),
                ],
            ],
        ];

        if ($body) {
            $request['request']['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($body, JSON_PRETTY_PRINT),
                'options' => [
                    'raw' => [
                        'language' => 'json',
                    ],
                ],
            ];
        }

        if ($exampleResponse) {
            $request['response'] = [
                [
                    'name' => 'Success Response',
                    'originalRequest' => $request['request'],
                    'status' => 'OK',
                    'code' => in_array($method, ['POST']) ? 201 : 200,
                    '_postman_previewlanguage' => 'json',
                    'header' => [
                        [
                            'key' => 'Content-Type',
                            'value' => 'application/json',
                        ],
                    ],
                    'cookie' => [],
                    'body' => json_encode($exampleResponse, JSON_PRETTY_PRINT),
                ],
            ];
        }

        return $request;
    }

    /**
     * Generates the request body for the create operation.
     */
    private function generateCreateBody(array $modelConfig, array $nestedModels = []): array
    {
        $body = [];

        if (! isset($modelConfig['fields'])) {
            return $body;
        }

        foreach ($modelConfig['fields'] as $fieldName => $fieldType) {
            if (in_array($fieldName, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }
            $body[$fieldName] = $this->generateExampleValue($fieldName, $fieldType);
        }

        // Add nested models data
        $body = array_merge($body, $this->generateNestedModelsBody($nestedModels));

        return $body;
    }

    /**
     * Generates the request body for the update operation.
     */
    private function generateUpdateBody(array $modelConfig, array $nestedModels = []): array
    {
        $body = $this->generateCreateBody($modelConfig, $nestedModels);
        $body['id'] = 1; // Add id for context in examples

        return $body;
    }

    /**
     * Generates nested models body data recursively.
     */
    private function generateNestedModelsBody(array $nestedModels): array
    {
        $body = [];

        foreach ($nestedModels as $nestedModelName => $nestedModelConfig) {
            $nestedResourceName = Str::snake(Str::plural($nestedModelName));
            $nestedBody = $this->generateNestedResourceBody($nestedModelConfig);

            // Handle deeper nesting
            if (isset($nestedModelConfig['_nested']) && ! empty($nestedModelConfig['_nested'])) {
                $deeperNestedBody = $this->generateNestedModelsBody($nestedModelConfig['_nested']);
                $nestedBody = array_merge($nestedBody, $deeperNestedBody);
            }

            $body[$nestedResourceName] = [$nestedBody];
        }

        return $body;
    }

    /**
     * Generates nested models response data recursively.
     */
    private function generateNestedModelsResponse(array $nestedModels): array
    {
        $responseData = [];

        foreach ($nestedModels as $nestedModelName => $nestedModelConfig) {
            $nestedResourceName = Str::snake(Str::plural($nestedModelName));
            $nestedResponse = array_merge(
                ['id' => 1],
                $this->generateNestedResourceBody($nestedModelConfig),
                [
                    'created_at' => now()->format('Y-m-d H:i:s'),
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ]
            );

            // Handle deeper nesting
            if (isset($nestedModelConfig['_nested']) && ! empty($nestedModelConfig['_nested'])) {
                $deeperNestedResponse = $this->generateNestedModelsResponse($nestedModelConfig['_nested']);
                $nestedResponse = array_merge($nestedResponse, $deeperNestedResponse);
            }

            $responseData[$nestedResourceName] = [$nestedResponse];
        }

        return $responseData;
    }

    /**
     * Generates the request body for a nested resource.
     */
    private function generateNestedResourceBody(array $nestedModelConfig): array
    {
        $body = [];

        foreach ($nestedModelConfig['fields'] ?? [] as $fieldName => $fieldType) {
            // Skip id, timestamps, and parent foreign keys for nested creation
            if (in_array($fieldName, ['id', 'created_at', 'updated_at', 'deleted_at']) ||
                (str_contains($fieldName, '_id') && str_contains($fieldType, 'foreignId'))) {
                continue;
            }
            $body[$fieldName] = $this->generateExampleValue($fieldName, $fieldType);
        }

        return $body;
    }

    /**
     * Generates an example value based on the field type.
     */
    private function generateExampleValue(string $fieldName, string $fieldType): mixed
    {
        $baseType = explode(':', $fieldType)[0];

        return match ($baseType) {
            'string' => 'example_'.$fieldName,
            'text' => 'This is an example '.$fieldName.' content.',
            'boolean' => str_contains($fieldType, 'default true') ? true : false,
            'integer', 'foreignId' => 1,
            'double', 'decimal' => 10.50,
            'date' => now()->format('Y-m-d'),
            'dateTime' => now()->format('Y-m-d H:i:s'),
            'timestamp' => now()->format('Y-m-d H:i:s'),
            default => str_contains($fieldType, 'nullable') ? null : 'example_value',
        };
    }

    /**
     * Generates the example response for the list operation.
     */
    private function generateListResponse(string $modelName, array $modelConfig, array $nestedModels = []): array
    {
        return [
            'data' => [
                $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedModels),
                $this->generateSampleRecord($modelName, $modelConfig, 2, $nestedModels),
            ],
            'meta' => [
                'current_page' => 1,
                'per_page' => 15,
                'total' => 2,
                'last_page' => 1,
            ],
        ];
    }

    /**
     * Generates the example response for the show operation.
     */
    private function generateShowResponse(string $modelName, array $modelConfig, array $nestedModels = []): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedModels),
        ];
    }

    /**
     * Generates the example response for the create operation.
     */
    private function generateCreateResponse(string $modelName, array $modelConfig, array $nestedModels = []): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedModels),
            'message' => $modelName.' created successfully',
        ];
    }

    /**
     * Generates the example response for the update operation.
     */
    private function generateUpdateResponse(string $modelName, array $modelConfig, array $nestedModels = []): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedModels),
            'message' => $modelName.' updated successfully',
        ];
    }

    /**
     * Generates the example response for the delete operation.
     */
    private function generateDeleteResponse(): array
    {
        return [
            'message' => 'Resource deleted successfully',
        ];
    }

    /**
     * Generates a sample record based on the model configuration.
     */
    private function generateSampleRecord(string $modelName, array $modelConfig, int $id = 1, array $nestedModels = []): array
    {
        $record = ['id' => $id];

        if (! isset($modelConfig['fields'])) {
            return $record;
        }

        foreach ($modelConfig['fields'] as $fieldName => $fieldType) {
            if ($fieldName === 'deleted_at') {
                continue; // Skip soft delete field in normal responses
            }
            $record[$fieldName] = $this->generateExampleValue($fieldName, $fieldType);
        }

        $record['created_at'] = now()->format('Y-m-d H:i:s');
        $record['updated_at'] = now()->format('Y-m-d H:i:s');

        // Add explicit relations
        if (isset($modelConfig['relations'])) {
            foreach ($modelConfig['relations'] as $relationName => $relationConfig) {
                if ($relationConfig['type'] === 'belongsTo') {
                    $record[$relationName] = [
                        'id' => 1,
                        'name' => 'Related '.$relationConfig['model'],
                    ];
                }
            }
        }

        // Add nested models in response (recursively)
        $nestedResponseData = $this->generateNestedModelsResponse($nestedModels);
        $record = array_merge($record, $nestedResponseData);

        return $record;
    }

    /**
     * Saves the generated Postman collection to a JSON file.
     */
    private function savePostmanCollection(string $outputFile): void
    {
        $jsonOutput = json_encode($this->collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        File::put($outputFile, $jsonOutput);

        $this->newLine();
        $this->info("🥵 Postman collection generated successfully: {$outputFile}");
        $this->info('📊 Generated endpoints for '.count($this->collection['item']).' models');
    }
}
