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

        if (! file_exists($yamlFilePath)) {
            $this->error("File not found: $yamlFilePath");

            return Command::FAILURE;
        }

        // Ensure output directory exists
        $outputDir = dirname($outputFilePath);
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $outputDir));
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
        $this->info('🎯 DB diagram generated successfully at: '.$outputFilePath);
        $this->info('📊 Generated tables for '.count($schema).' models');

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
        if (! isset($tableDefinition['fields']['created_at'])) {
            $output .= "  created_at datetime\n";
        }
        if (! isset($tableDefinition['fields']['updated_at'])) {
            $output .= "  updated_at datetime\n";
        }

        $output .= "}\n\n";

        return $output;
    }

    /**
     * Parses a single field definition from the YAML schema.
     * Now supports both old array format and new string format.
     */
    protected function parseField(string $fieldName, array|string $fieldDefinition): string
    {
        $type = 'string';
        $modifiersNote = '';

        if (is_string($fieldDefinition)) {
            // New format: "string:unique" or "foreignId:categories:nullable"
            $parts = explode(':', $fieldDefinition);
            $fieldType = $parts[0];
            $type = $this->mapFieldType($fieldType);

            // Check for nullable modifier
            if (in_array('nullable', $parts)) {
                $modifiersNote .= ' [note: "nullable"]';
            }

            // Check for unique modifier
            if (in_array('unique', $parts)) {
                $modifiersNote .= ' [note: "unique"]';
            }

            // Check for the default value
            foreach ($parts as $part) {
                if (str_starts_with($part, 'default ')) {
                    $defaultValue = substr($part, 8);
                    $modifiersNote .= ' [note: "default: '.$defaultValue.'"]';
                }
            }
        } elseif (is_array($fieldDefinition)) {
            // Old format: array with 'type', 'nullable', etc.
            $type = $this->mapFieldType($fieldDefinition['type'] ?? 'string');
            if (isset($fieldDefinition['nullable']) && $fieldDefinition['nullable']) {
                $modifiersNote .= ' [note: "nullable"]';
            }
        }

        return "  $fieldName $type$modifiersNote\n";
    }

    /**
     * Maps Laravel migration field types to DBML types.
     */
    protected function mapFieldType(?string $laravelType): string
    {
        return match ($laravelType) {
            'foreignId', 'integer' => 'integer',
            'text' => 'text',
            'boolean' => 'boolean',
            'double' => 'double',
            'decimal' => 'decimal',
            'date' => 'date',
            'dateTime', 'timestamp', 'softDeletes' => 'datetime',
            default => 'string',
        };
    }

    /**
     * Parses relations from the new compact format into normalized structure.
     */
    protected function parseRelations(array $tableDefinition): array
    {
        $relations = [];

        if (! isset($tableDefinition['relations'])) {
            return $relations;
        }

        // Check if it's the old format (array with relation names as keys)
        $firstRelation = reset($tableDefinition['relations']);
        if (is_array($firstRelation) && isset($firstRelation['type'])) {
            // Old format
            return $tableDefinition['relations'];
        }

        // New format: parse compact relation strings
        foreach ($tableDefinition['relations'] as $relationType => $relationString) {
            if (! is_string($relationString)) {
                continue;
            }

            // Split by comma to get individual relations
            $relationParts = array_map('trim', explode(',', $relationString));

            foreach ($relationParts as $relationPart) {
                // Parse "Model:functionName" format
                if (str_contains($relationPart, ':')) {
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
     * Generates the DBML relationship definitions for a table.
     */
    protected function generateRelationships(string $tableName, array $tableDefinition): string
    {
        $output = '';
        $relations = $this->parseRelations($tableDefinition);

        foreach ($relations as $relationName => $relation) {
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
            'parent' => 'parent_id',
        ];

        // camelCase to snake_case conversion for foreign key
        $foreignKey = $columnOverrides[$relationName] ?? (Str::snake($relationName).'_id');
        $fromTableName = Str::snake(Str::pluralStudly($fromTable));

        if (isset($relation['type']) && $relation['type'] === 'belongsTo') {
            $targetModel = $relation['model'];
            $targetTableName = Str::snake(Str::pluralStudly($targetModel));

            return "Ref: $fromTableName.$foreignKey > $targetTableName.id\n";
        }

        return '';
    }
}
