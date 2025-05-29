<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class GenerateDbDiagram extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dbdiagram:generate 
                             {--file= : Path to the YAML schema file}
                             {--output= : Path to the output DBML file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate dbdiagram.io syntax (DBML) from YAML schema';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get config values
        $config = config('module-generator');

        // Resolve options with config fallbacks
        $yamlFilePath = $this->option('file') ?: $config['models_path'];
        $outputFilePath = $this->option('output') ?: $config['dbdiagram']['output_path'];

        $this->info("Reading YAML schema from: {$yamlFilePath}");
        $this->info("Output will be saved to: {$outputFilePath}");

        if (!file_exists($yamlFilePath)) {
            $this->error("File not found: $yamlFilePath");
            return Command::FAILURE;
        }

        // Ensure output directory exists
        $outputDir = dirname($outputFilePath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $schema = Yaml::parseFile($yamlFilePath);
        $dbmlOutput = '';

        // Generate table definitions
        foreach ($schema as $tableName => $tableDefinition) {
            $dbmlOutput .= $this->generateTableDefinition($tableName, $tableDefinition);
        }

        // Generate relationships
        foreach ($schema as $tableName => $tableDefinition) {
            $dbmlOutput .= $this->generateRelationships($tableName, $tableDefinition);
        }

        file_put_contents($outputFilePath, $dbmlOutput);
        $this->info('🎯 DB diagram generated successfully at: ' . $outputFilePath);
        $this->info('📊 Generated tables for ' . count($schema) . ' models');

        return Command::SUCCESS;
    }

    /**
     * Generates the DBML definition for a single table.
     */
    protected function generateTableDefinition(string $tableName, array $tableDefinition): string
    {
        $targetTableName = Str::snake(Str::pluralStudly($tableName));
        $output = "Table $targetTableName {\n";
        $output .= "  id integer [primary key]\n"; // Add default primary key

        foreach ($tableDefinition['fields'] ?? [] as $fieldName => $fieldDefinition) {
            $output .= $this->parseField($fieldName, $fieldDefinition);
        }

        // Add timestamps if not explicitly defined
        if (!isset($tableDefinition['fields']['created_at'])) {
            $output .= "  created_at datetime\n";
        }
        if (!isset($tableDefinition['fields']['updated_at'])) {
            $output .= "  updated_at datetime\n";
        }

        $output .= "}\n\n";

        return $output;
    }

    /**
     * Parses a single field definition from the YAML schema.
     */
    protected function parseField(string $fieldName, array|string $fieldDefinition): string
    {
        $type = 'string';
        $modifiersNote = '';

        if (is_string($fieldDefinition)) {
            [$fieldType, $modifiers] = explode(':', $fieldDefinition, 2) + [null, null];
            $type = $this->mapFieldType($fieldType);
            if ($modifiers && str_contains($modifiers, 'nullable')) {
                $modifiersNote .= ' [note: "nullable"]';
            }
        } elseif (is_array($fieldDefinition)) {
            $type = $this->mapFieldType($fieldDefinition['type'] ?? 'string');
            if (isset($fieldDefinition['nullable']) && $fieldDefinition['nullable']) {
                $modifiersNote .= ' [note: "nullable"]';
            }
            // You could add more attribute parsing here if needed (e.g., default values)
        }

        return "  $fieldName $type$modifiersNote\n";
    }

    /**
     * Maps Laravel migration field types to DBML types.
     */
    protected function mapFieldType(?string $laravelType): string
    {
        return match ($laravelType) {
            'foreignId' => 'integer',
            'string' => 'string',
            'text' => 'text',
            'boolean' => 'boolean',
            'integer' => 'integer',
            'double' => 'double',
            'decimal' => 'decimal',
            'date' => 'date',
            'dateTime', 'timestamp' => 'datetime',
            'softDeletes' => 'datetime',
            default => 'string',
        };
    }

    /**
     * Generates the DBML relationship definitions for a table.
     */
    protected function generateRelationships(string $tableName, array $tableDefinition): string
    {
        $output = '';
        foreach ($tableDefinition['relations'] ?? [] as $relationName => $relation) {
            $output .= $this->parseRelation($tableName, $relationName, $relation);
        }

        return $output;
    }

    /**
     * Parses a single relationship definition from the YAML schema.
     */
    protected function parseRelation(string $fromTable, string $relationName, array $relation): string
    {
        $columnOverrides = [
            'creator' => 'created_by',
            'updater' => 'updated_by',
        ];

        $foreignKey = $columnOverrides[$relationName] ?? ($relationName.'_id');
        $fromTableName = Str::snake(Str::pluralStudly($fromTable));

        if ($relation['type'] === 'belongsTo') {
            $targetModel = $relation['model'];
            $targetTableName = Str::snake(Str::pluralStudly($targetModel));

            return "Ref: $fromTableName.$foreignKey > $targetTableName.id\n";
        }

        return '';
    }
}