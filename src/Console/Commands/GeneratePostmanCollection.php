<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class GeneratePostmanCollection extends Command
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
        $this->info("Base URL: {$this->baseUrl}, API Prefix: {$this->apiPrefix}");

        try {
            $this->fullSchema = Yaml::parseFile($yamlFile);
            $this->collection = $this->initializeCollection();

            foreach ($this->fullSchema as $modelName => $modelConfig) {
                if ($this->shouldGenerateController($modelConfig)) {
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
     * Gets relations that have makeRequest: true.
     * Only hasMany and hasOne relations are allowed to prevent circular references.
     */
    private function getRequestableRelations(array $modelConfig): array
    {
        $requestableRelations = [];

        if (! isset($modelConfig['relations'])) {
            return $requestableRelations;
        }

        foreach ($modelConfig['relations'] as $relationName => $relationConfig) {
            if (isset($relationConfig['makeRequest']) &&
                $relationConfig['makeRequest'] === true &&
                in_array($relationConfig['type'], ['hasMany', 'hasOne'])) {
                $requestableRelations[$relationName] = $relationConfig;
            }
        }

        return $requestableRelations;
    }

    /**
     * Recursively collects all nested requestable relations.
     * Includes circular reference detection to prevent infinite loops.
     */
    private function collectNestedRequestableRelations(string $modelName, array $visited = []): array
    {
        // Prevent circular references
        if (in_array($modelName, $visited)) {
            return [];
        }

        $visited[] = $modelName;
        $nestedRelations = [];
        $modelConfig = $this->fullSchema[$modelName] ?? [];

        $directRelations = $this->getRequestableRelations($modelConfig);

        foreach ($directRelations as $relationName => $relationConfig) {
            $relatedModelName = $relationConfig['model'];
            $nestedRelations[$relationName] = [
                'config' => $relationConfig,
                'model_config' => $this->fullSchema[$relatedModelName] ?? [],
                'nested' => $this->collectNestedRequestableRelations($relatedModelName, $visited),
            ];
        }

        return $nestedRelations;
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

        // Get nested requestable relations
        $nestedRelations = $this->collectNestedRequestableRelations($modelName);

        // List Resources
        $modelFolder['item'][] = $this->createRequest(
            'Get All '.Str::plural($modelName),
            'GET',
            "{$this->apiPrefix}/{$resourceName}",
            null,
            $this->generateListResponse($modelName, $modelConfig, $nestedRelations)
        );

        // Show Resource
        $modelFolder['item'][] = $this->createRequest(
            'Get '.$modelName.' by ID',
            'GET',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            null,
            $this->generateShowResponse($modelName, $modelConfig, $nestedRelations)
        );

        // Store Resource
        $modelFolder['item'][] = $this->createRequest(
            'Create '.$modelName,
            'POST',
            "{$this->apiPrefix}/{$resourceName}",
            $this->generateCreateBody($modelConfig, $nestedRelations),
            $this->generateCreateResponse($modelName, $modelConfig, $nestedRelations)
        );

        // Update Resource
        $modelFolder['item'][] = $this->createRequest(
            'Update '.$modelName,
            'POST',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            $this->generateUpdateBody($modelConfig, $nestedRelations),
            $this->generateUpdateResponse($modelName, $modelConfig, $nestedRelations)
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
    private function generateCreateBody(array $modelConfig, array $nestedRelations = []): array
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

        // Add nested relations data
        $body = array_merge($body, $this->generateNestedRelationsBody($nestedRelations));

        return $body;
    }

    /**
     * Generates the request body for the update operation.
     */
    private function generateUpdateBody(array $modelConfig, array $nestedRelations = []): array
    {
        $body = $this->generateCreateBody($modelConfig, $nestedRelations);
        $body['_method'] = 'PUT';
        $body['id'] = 1; // Add id for context in examples

        return $body;
    }

    /**
     * Generates nested relations body data recursively.
     */
    private function generateNestedRelationsBody(array $nestedRelations): array
    {
        $body = [];

        foreach ($nestedRelations as $relationName => $relationData) {
            $relationConfig = $relationData['config'];
            $modelConfig = $relationData['model_config'];
            $nestedData = $relationData['nested'];

            // Generate the relation field name (pluralized snake_case for hasMany)
            $fieldName = $this->getRelationFieldName($relationName, $relationConfig);

            $relationBody = $this->generateRelationBody($modelConfig);

            // Handle deeper nesting recursively
            if (! empty($nestedData)) {
                $deeperNestedBody = $this->generateNestedRelationsBody($nestedData);
                $relationBody = array_merge($relationBody, $deeperNestedBody);
            }

            // For hasMany/hasOne relations, wrap hasMany in array
            if ($relationConfig['type'] === 'hasMany') {
                $body[$fieldName] = [$relationBody];
            } else {
                $body[$fieldName] = $relationBody;
            }
        }

        return $body;
    }

    /**
     * Generates nested relations response data recursively.
     */
    private function generateNestedRelationsResponse(array $nestedRelations): array
    {
        $responseData = [];

        foreach ($nestedRelations as $relationName => $relationData) {
            $relationConfig = $relationData['config'];
            $modelConfig = $relationData['model_config'];
            $nestedData = $relationData['nested'];

            $fieldName = $this->getRelationFieldName($relationName, $relationConfig);

            $relationResponse = array_merge(
                ['id' => 1],
                $this->generateRelationBody($modelConfig),
                [
                    'created_at' => now()->format('Y-m-d H:i:s'),
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ]
            );

            // Handle deeper nesting recursively
            if (! empty($nestedData)) {
                $deeperNestedResponse = $this->generateNestedRelationsResponse($nestedData);
                $relationResponse = array_merge($relationResponse, $deeperNestedResponse);
            }

            // For hasMany/hasOne relations, wrap hasMany in array
            if ($relationConfig['type'] === 'hasMany') {
                $responseData[$fieldName] = [$relationResponse];
            } else {
                $responseData[$fieldName] = $relationResponse;
            }
        }

        return $responseData;
    }

    /**
     * Gets the appropriate field name for a relation.
     */
    private function getRelationFieldName(string $relationName, array $relationConfig): string
    {
        if (in_array($relationConfig['type'], ['hasMany'])) {
            return Str::snake(Str::plural($relationName));
        }

        return Str::snake($relationName);
    }

    /**
     * Generates the request body for a relation.
     */
    private function generateRelationBody(array $modelConfig): array
    {
        $body = [];

        foreach ($modelConfig['fields'] ?? [] as $fieldName => $fieldType) {
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
    private function generateListResponse(string $modelName, array $modelConfig, array $nestedRelations = []): array
    {
        return [
            'data' => [
                $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedRelations),
                $this->generateSampleRecord($modelName, $modelConfig, 2, $nestedRelations),
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
    private function generateShowResponse(string $modelName, array $modelConfig, array $nestedRelations = []): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedRelations),
        ];
    }

    /**
     * Generates the example response for the create operation.
     */
    private function generateCreateResponse(string $modelName, array $modelConfig, array $nestedRelations = []): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedRelations),
            'message' => $modelName.' created successfully',
        ];
    }

    /**
     * Generates the example response for the update operation.
     */
    private function generateUpdateResponse(string $modelName, array $modelConfig, array $nestedRelations = []): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1, $nestedRelations),
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
    private function generateSampleRecord(string $modelName, array $modelConfig, int $id = 1, array $nestedRelations = []): array
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

        // Add explicit relations (belongsTo relations without makeRequest)
        if (isset($modelConfig['relations'])) {
            foreach ($modelConfig['relations'] as $relationName => $relationConfig) {
                if ($relationConfig['type'] === 'belongsTo' &&
                    (! isset($relationConfig['makeRequest']) || ! $relationConfig['makeRequest'])) {
                    $record[$relationName] = [
                        'id' => 1,
                        'name' => 'Related '.$relationConfig['model'],
                    ];
                }
            }
        }

        // Add nested relations in response (recursively)
        $nestedResponseData = $this->generateNestedRelationsResponse($nestedRelations);
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
