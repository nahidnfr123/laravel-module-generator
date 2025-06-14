<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands\backups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

use function NahidFerdous\LaravelModuleGenerator\Console\Commands\config;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\env;
use function NahidFerdous\LaravelModuleGenerator\Console\Commands\now;

class GeneratePostmanCollectionBackup extends Command
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
            // If no output specified, use config or generate random
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
            $schema = Yaml::parseFile($yamlFile);
            $this->collection = $this->initializeCollection();

            foreach ($schema as $modelName => $modelConfig) {
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
     * Generates the Postman endpoints for a given model.
     */
    private function generateModelEndpoints(string $modelName, array $modelConfig): void
    {
        $resourceName = Str::kebab(Str::plural($modelName));

        $modelFolder = [
            'name' => Str::plural($modelName),
            'item' => [],
        ];

        // List Resources
        $modelFolder['item'][] = $this->createRequest(
            'Get All '.Str::plural($modelName),
            'GET',
            "{$this->apiPrefix}/{$resourceName}",
            null,
            $this->generateListResponse($modelName, $modelConfig)
        );

        // Show Resource
        $modelFolder['item'][] = $this->createRequest(
            'Get '.$modelName.' by ID',
            'GET',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            null,
            $this->generateShowResponse($modelName, $modelConfig)
        );

        // Store Resource
        $modelFolder['item'][] = $this->createRequest(
            'Create '.$modelName,
            'POST',
            "{$this->apiPrefix}/{$resourceName}",
            $this->generateCreateBody($modelConfig),
            $this->generateCreateResponse($modelName, $modelConfig)
        );

        // Update Resource
        $modelFolder['item'][] = $this->createRequest(
            'Update '.$modelName,
            'PUT',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            $this->generateUpdateBody($modelConfig),
            $this->generateUpdateResponse($modelName, $modelConfig)
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
    private function generateCreateBody(array $modelConfig): array
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

        return $body;
    }

    /**
     * Generates the request body for the update operation.
     */
    private function generateUpdateBody(array $modelConfig): array
    {
        $body = $this->generateCreateBody($modelConfig);
        $body['id'] = 1; // Add id for context in examples

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
            default => str_contains($fieldType, 'nullable') ? null : 'example_value',
        };
    }

    /**
     * Generates the example response for the list operation.
     */
    private function generateListResponse(string $modelName, array $modelConfig): array
    {
        return [
            'data' => [
                $this->generateSampleRecord($modelName, $modelConfig, 1),
                $this->generateSampleRecord($modelName, $modelConfig, 2),
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
    private function generateShowResponse(string $modelName, array $modelConfig): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1),
        ];
    }

    /**
     * Generates the example response for the create operation.
     */
    private function generateCreateResponse(string $modelName, array $modelConfig): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1),
            'message' => $modelName.' created successfully',
        ];
    }

    /**
     * Generates the example response for the update operation.
     */
    private function generateUpdateResponse(string $modelName, array $modelConfig): array
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1),
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
    private function generateSampleRecord(string $modelName, array $modelConfig, int $id = 1): array
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
