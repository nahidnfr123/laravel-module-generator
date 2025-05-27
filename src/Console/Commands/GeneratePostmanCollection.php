<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class GeneratePostmanCollection extends Command
{
    protected $signature = 'postman:generate
                           {--file=module/models.yaml : Path to the YAML schema file}
                           {--base-url={{base-url}} : Base URL for API}
                           {--prefix=api/v1 : API prefix}';

    protected $description = 'Generate Postman collection from YAML schema';

    private $baseUrl;
    private $apiPrefix;
    private $collection;

    public function handle()
    {
        $yamlFile = $this->option('file');

        // Generate random number for output file
        $randomNumber = rand(100, 999);
        $outputFile = "module/postman_collection_{$randomNumber}.json";

        // Ensure module directory exists
        if (!File::exists('module')) {
            File::makeDirectory('module', 0755, true);
        }

        $this->baseUrl = rtrim($this->option('base-url'), '/');
        $this->apiPrefix = trim($this->option('prefix'), '/');

        if (!File::exists($yamlFile)) {
            $this->error("YAML file not found: {$yamlFile}");
            return 1;
        }

        $this->info("Parsing YAML schema from: {$yamlFile}");

        try {
            $yamlContent = File::get($yamlFile);
            $schema = Yaml::parse($yamlContent);

            $this->collection = $this->initializeCollection();

            foreach ($schema as $modelName => $modelConfig) {
                if ($this->shouldGenerateController($modelConfig)) {
                    $this->generateModelEndpoints($modelName, $modelConfig);
                }
            }

            $jsonOutput = json_encode($this->collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            File::put($outputFile, $jsonOutput);

            $this->newLine();
            $this->info("🥵 Postman collection generated successfully: {$outputFile}");
            $this->info("📊 Generated endpoints for " . count($this->collection['item']) . " models");

        } catch (\Exception $e) {
            $this->error("Error generating collection: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function initializeCollection()
    {
        return [
            'info' => [
                'name' => 'Laravel API Collection',
                'description' => 'Auto-generated from YAML schema',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                '_postman_id' => Str::uuid()->toString(),
            ],
            'item' => [],
            'variable' => [
                [
                    'key' => 'baseUrl',
                    'value' => $this->baseUrl,
                    'type' => 'string'
                ],
                [
                    'key' => 'token',
                    'value' => '',
                    'type' => 'string'
                ]
            ]
        ];
    }

    private function shouldGenerateController($modelConfig)
    {
        return !isset($modelConfig['generate']['controller']) || $modelConfig['generate']['controller'] !== false;
    }

    private function generateModelEndpoints($modelName, $modelConfig)
    {
        $resourceName = Str::kebab(Str::plural($modelName));
        $singularName = Str::kebab($modelName);

        $modelFolder = [
            'name' => Str::plural($modelName),
            'item' => []
        ];

        // GET /resources (List)
        $modelFolder['item'][] = $this->createRequest(
            'Get All ' . Str::plural($modelName),
            'GET',
            "{$this->apiPrefix}/{$resourceName}",
            null,
            $this->generateListResponse($modelName, $modelConfig)
        );

        // GET /resources/{id} (Show)
        $modelFolder['item'][] = $this->createRequest(
            'Get ' . $modelName . ' by ID',
            'GET',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            null,
            $this->generateShowResponse($modelName, $modelConfig)
        );

        // POST /resources (Store)
        $modelFolder['item'][] = $this->createRequest(
            'Create ' . $modelName,
            'POST',
            "{$this->apiPrefix}/{$resourceName}",
            $this->generateCreateBody($modelConfig),
            $this->generateCreateResponse($modelName, $modelConfig)
        );

        // PUT /resources/{id} (Update)
        $modelFolder['item'][] = $this->createRequest(
            'Update ' . $modelName,
            'PUT',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            $this->generateUpdateBody($modelConfig),
            $this->generateUpdateResponse($modelName, $modelConfig)
        );

        // DELETE /resources/{id} (Delete)
        $modelFolder['item'][] = $this->createRequest(
            'Delete ' . $modelName,
            'DELETE',
            "{$this->apiPrefix}/{$resourceName}/{{id}}",
            null,
            $this->generateDeleteResponse()
        );

        $this->collection['item'][] = $modelFolder;
    }

    private function createRequest($name, $method, $url, $body = null, $exampleResponse = null)
    {
        $request = [
            'name' => $name,
            'request' => [
                'method' => $method,
                'header' => [
                    [
                        'key' => 'Accept',
                        'value' => 'application/json',
                        'type' => 'text'
                    ],
                    [
                        'key' => 'Content-Type',
                        'value' => 'application/json',
                        'type' => 'text'
                    ],
                    [
                        'key' => 'Authorization',
                        'value' => 'Bearer {{token}}',
                        'type' => 'text'
                    ]
                ],
                'url' => [
                    'raw' => '{{baseUrl}}/' . $url,
                    'host' => ['{{baseUrl}}'],
                    'path' => explode('/', $url)
                ]
            ]
        ];

        if ($body) {
            $request['request']['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($body, JSON_PRETTY_PRINT),
                'options' => [
                    'raw' => [
                        'language' => 'json'
                    ]
                ]
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
                            'value' => 'application/json'
                        ]
                    ],
                    'cookie' => [],
                    'body' => json_encode($exampleResponse, JSON_PRETTY_PRINT)
                ]
            ];
        }

        return $request;
    }

    private function generateCreateBody($modelConfig)
    {
        $body = [];

        if (!isset($modelConfig['fields'])) {
            return $body;
        }

        foreach ($modelConfig['fields'] as $fieldName => $fieldType) {
            // Skip auto-generated fields
            if (in_array($fieldName, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $body[$fieldName] = $this->generateExampleValue($fieldName, $fieldType);
        }

        return $body;
    }

    private function generateUpdateBody($modelConfig)
    {
        $body = $this->generateCreateBody($modelConfig);

        // Add id for context in examples
        $body['id'] = 1;

        return $body;
    }

    private function generateExampleValue($fieldName, $fieldType)
    {
        $baseType = explode(':', $fieldType)[0];

        switch ($baseType) {
            case 'string':
                return "example_" . $fieldName;
            case 'text':
                return "This is an example " . $fieldName . " content.";
            case 'boolean':
                return str_contains($fieldType, 'default true') ? true : false;
            case 'integer':
            case 'foreignId':
                return 1;
            case 'double':
            case 'decimal':
                return 10.50;
            case 'date':
                return now()->format('Y-m-d');
            case 'dateTime':
                return now()->format('Y-m-d H:i:s');
            default:
                if (str_contains($fieldType, 'nullable')) {
                    return null;
                }
                return "example_value";
        }
    }

    private function generateListResponse($modelName, $modelConfig)
    {
        return [
            'data' => [
                $this->generateSampleRecord($modelName, $modelConfig, 1),
                $this->generateSampleRecord($modelName, $modelConfig, 2)
            ],
            'meta' => [
                'current_page' => 1,
                'per_page' => 15,
                'total' => 2,
                'last_page' => 1
            ]
        ];
    }

    private function generateShowResponse($modelName, $modelConfig)
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1)
        ];
    }

    private function generateCreateResponse($modelName, $modelConfig)
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1),
            'message' => $modelName . ' created successfully'
        ];
    }

    private function generateUpdateResponse($modelName, $modelConfig)
    {
        return [
            'data' => $this->generateSampleRecord($modelName, $modelConfig, 1),
            'message' => $modelName . ' updated successfully'
        ];
    }

    private function generateDeleteResponse()
    {
        return [
            'message' => 'Resource deleted successfully'
        ];
    }

    private function generateSampleRecord($modelName, $modelConfig, $id = 1)
    {
        $record = ['id' => $id];

        if (!isset($modelConfig['fields'])) {
            return $record;
        }

        foreach ($modelConfig['fields'] as $fieldName => $fieldType) {
            if ($fieldName === 'deleted_at') {
                continue; // Skip soft delete field in normal responses
            }

            $record[$fieldName] = $this->generateExampleValue($fieldName, $fieldType);
        }

        // Add timestamps
        $record['created_at'] = now()->format('Y-m-d H:i:s');
        $record['updated_at'] = now()->format('Y-m-d H:i:s');

        // Add related data examples for relations
        if (isset($modelConfig['relations'])) {
            foreach ($modelConfig['relations'] as $relationName => $relationConfig) {
                if ($relationConfig['type'] === 'belongsTo') {
                    $record[$relationName] = [
                        'id' => 1,
                        'name' => 'Related ' . $relationConfig['model']
                    ];
                }
            }
        }

        return $record;
    }
}
