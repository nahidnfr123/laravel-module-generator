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

    private string $baseUrl;

    private string $apiPrefix;

    private array $collection;

    private array $fullSchema;

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
        // New format: check generate_except
        if (isset($modelConfig['generate_except'])) {
            $exceptions = is_array($modelConfig['generate_except'])
                ? $modelConfig['generate_except']
                : explode(',', str_replace(' ', '', $modelConfig['generate_except']));

            return ! in_array('controller', array_map('trim', $exceptions), true);
        }

        // Old format: check generate.controller
        if (isset($modelConfig['generate']['controller'])) {
            return $modelConfig['generate']['controller'] !== false;
        }

        // Default: generate controller
        return true;
    }

    /**
     * Parses relations from the new compact format into a normalized structure.
     */
    private function parseRelations(array $modelConfig): array
    {
        $relations = [];

        if (! isset($modelConfig['relations'])) {
            return $relations;
        }

        foreach ($modelConfig['relations'] as $relationType => $relationString) {
            if (! is_string($relationString)) {
                continue;
            }

            // Split by comma to get individual relations
            $relationParts = array_map('trim', explode(',', $relationString));

            foreach ($relationParts as $relationPart) {
                // Parse "Model:functionName" format
                if (strpos($relationPart, ':') !== false) {
                    [$model, $functionName] = explode(':', $relationPart, 2);
                    $model = trim($model);
                    $functionName = trim($functionName);

                    $relations[$functionName] = [
                        'type' => $relationType,
                        'model' => $model,
                    ];
                }
            }
        }

        return $relations;
    }

    /**
     * Gets relations that have makeRequest: true.
     * Only hasMany and hasOne relations are allowed to prevent circular references.
     */
    private function getRequestableRelations(array $modelConfig): array
    {
        $requestableRelations = [];
        $parsedRelations = $this->parseRelations($modelConfig);

        // Get nested_requests list
        $makeRequests = [];
        if (isset($modelConfig['nested_requests'])) {
            $makeRequests = is_array($modelConfig['nested_requests'])
                ? $modelConfig['nested_requests']
                : array_map('trim', explode(',', $modelConfig['nested_requests']));
        }

        foreach ($parsedRelations as $relationName => $relationConfig) {
            if (in_array($relationName, $makeRequests, true) &&
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
            "{$this->apiPrefix}/{$resourceName}/:id",
            null,
            $this->generateShowResponse($modelName, $modelConfig, $nestedRelations)
        );

        // Store Resource
        $hasFiles = $this->hasFileFields($modelConfig, $nestedRelations);
        $modelFolder['item'][] = $this->createRequest(
            'Create '.$modelName,
            'POST',
            "{$this->apiPrefix}/{$resourceName}",
            $this->generateCreateBody($modelConfig, $nestedRelations),
            $this->generateCreateResponse($modelName, $modelConfig, $nestedRelations),
            $hasFiles
        );

        // Update Resource
        $modelFolder['item'][] = $this->createRequest(
            'Update '.$modelName,
            'POST',
            "{$this->apiPrefix}/{$resourceName}/:id",
            $this->generateUpdateBody($modelConfig, $nestedRelations),
            $this->generateUpdateResponse($modelName, $modelConfig, $nestedRelations),
            $hasFiles
        );

        // Delete Resource
        $modelFolder['item'][] = $this->createRequest(
            'Delete '.$modelName,
            'DELETE',
            "{$this->apiPrefix}/{$resourceName}/:id",
            null,
            $this->generateDeleteResponse()
        );

        $this->collection['item'][] = $modelFolder;
    }

    /**
     * Creates a single Postman request item.
     *
     */
    private function createRequest(string $name, string $method, string $url, ?array $body = null, ?array $exampleResponse = null, bool $hasFiles = false): array
    {
        $headers = [
            [
                'key' => 'Accept',
                'value' => 'application/json',
                'type' => 'text',
            ],
            [
                'key' => 'Authorization',
                'value' => 'Bearer {{token}}',
                'type' => 'text',
            ],
        ];

        // Only add Content-Type for JSON requests
        if (! $hasFiles) {
            $headers[] = [
                'key' => 'Content-Type',
                'value' => 'application/json',
                'type' => 'text',
            ];
        }

        $request = [
            'name' => $name,
            'request' => [
                'method' => $method,
                'header' => $headers,
                'url' => [
                    'raw' => '{{baseUrl}}/'.$url,
                    'host' => ['{{baseUrl}}'],
                    'path' => explode('/', $url),
                ],
            ],
        ];

        if ($body) {
            if ($hasFiles) {
                // Generate form-data body
                $request['request']['body'] = [
                    'mode' => 'formdata',
                    'formdata' => $this->convertToFormData($body),
                ];
            } else {
                // Generate raw JSON body
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
        }

        return $request;
    }

    /**
     * Converts a body array to Postman form-data format.
     */
    private function convertToFormData(array $body, string $prefix = ''): array
    {
        $formData = [];

        foreach ($body as $key => $value) {
            $fieldKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                // Handle nested arrays recursively
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // Indexed array - handle each item
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            $nestedFormData = $this->convertToFormData($item, "{$fieldKey}[{$index}]");
                            $formData = array_merge($formData, $nestedFormData);
                        } else {
                            $formData[] = [
                                'key' => "{$fieldKey}[{$index}]",
                                'value' => (string) $item,
                                'type' => 'text',
                            ];
                        }
                    }
                } else {
                    // Associative array - recurse
                    $nestedFormData = $this->convertToFormData($value, $fieldKey);
                    $formData = array_merge($formData, $nestedFormData);
                }
            } elseif ($value === null) {
                // Handle null values
                $formData[] = [
                    'key' => $fieldKey,
                    'value' => '',
                    'type' => 'text',
                ];
            } else {
                $formData[] = [
                    'key' => $fieldKey,
                    'value' => (string) $value,
                    'type' => 'text',
                ];
            }
        }

        return $formData;
    }

    /**
     * Checks if the model config has any file or image fields.
     */
    private function hasFileFields(array $modelConfig, array $nestedRelations = []): bool
    {
        // Check main model fields
        if (isset($modelConfig['fields'])) {
            foreach ($modelConfig['fields'] as $fieldName => $fieldType) {
                $baseType = explode(':', $fieldType)[0];
                if (in_array($baseType, ['file', 'image'])) {
                    return true;
                }
            }
        }

        // Check nested relations recursively
        return array_any($nestedRelations, fn ($relationData) => $this->hasFileFields($relationData['model_config'], $relationData['nested']));
        //        foreach ($nestedRelations as $relationData) {
        //            if ($this->hasFileFields($relationData['model_config'], $relationData['nested'])) {
        //                return true;
        //            }
        //        }
        //        return false;
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
        return array_merge($body, $this->generateNestedRelationsBody($nestedRelations));
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
     * Generates nested relation body data recursively.
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

            // For hasMany/hasOne relations, wrap hasMany in an array
            if ($relationConfig['type'] === 'hasMany') {
                $body[$fieldName] = [$relationBody];
            } else {
                $body[$fieldName] = $relationBody;
            }
        }

        return $body;
    }

    /**
     * Generates nested relation response data recursively.
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
            'boolean' => str_contains($fieldType, 'default true'),
            'integer', 'foreignId' => 1,
            'double', 'decimal' => 10.50,
            'date' => now()->format('Y-m-d'),
            'dateTime', 'timestamp' => now()->format('Y-m-d H:i:s'),
            'file', 'image' => null,
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
                continue; // Skip the soft delete field in normal responses
            }
            $record[$fieldName] = $this->generateExampleValue($fieldName, $fieldType);
        }

        $record['created_at'] = now()->format('Y-m-d H:i:s');
        $record['updated_at'] = now()->format('Y-m-d H:i:s');

        // Add explicit relations (belongsTo relations without makeRequest)
        $parsedRelations = $this->parseRelations($modelConfig);
        $makeRequests = [];
        if (isset($modelConfig['nested_requests'])) {
            $makeRequests = is_array($modelConfig['nested_requests'])
                ? $modelConfig['nested_requests']
                : array_map('trim', explode(',', $modelConfig['nested_requests']));
        }

        foreach ($parsedRelations as $relationName => $relationConfig) {
            if ($relationConfig['type'] === 'belongsTo' && ! in_array($relationName, $makeRequests, true)) {
                $record[$relationName] = [
                    'id' => 1,
                    'name' => 'Related '.$relationConfig['model'],
                ];
            }
        }

        // Add nested relations in response (recursively)
        $nestedResponseData = $this->generateNestedRelationsResponse($nestedRelations);

        return array_merge($record, $nestedResponseData);
    }

    /**
     * Saves the generated Postman collection to a JSON file.
     *
     * @throws \JsonException
     */
    private function savePostmanCollection(string $outputFile): void
    {
        $jsonOutput = json_encode($this->collection, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        File::put($outputFile, $jsonOutput);

        $this->newLine();
        $this->info("🥵 Postman collection generated successfully: {$outputFile}");
        $this->info('📊 Generated endpoints for '.count($this->collection['item']).' models');
    }
}
