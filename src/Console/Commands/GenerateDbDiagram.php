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
    protected $signature = 'dbdiagram:generate {--file=module/models.yaml : Path to the YAML schema file} {--output=module/dbdiagram.dbml : Path to the output DBML file}';

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
        $yamlFilePath = $this->option('file') ?? config('module-generator.models_path', 'module/models.yaml');
        $outputFilePath = $this->option('output');

        if (!file_exists($yamlFilePath)) {
            $this->error("File not found: $yamlFilePath");
            return Command::FAILURE;
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
        $this->info("DB diagram generated at: " . $outputFilePath);
        return Command::SUCCESS;
    }

    /**
     * Generates the DBML definition for a single table.
     *
     * @param string $tableName
     * @param array $tableDefinition
     * @return string
     */
    protected function generateTableDefinition(string $tableName, array $tableDefinition): string
    {
        $targetTableName = Str::snake(Str::pluralStudly($tableName));
        $output = "Table $targetTableName {\n";
        $output .= "  id integer [primary key]\n"; // Add default primary key

        foreach ($tableDefinition['fields'] ?? [] as $fieldName => $fieldDefinition) {
            $output .= $this->parseField($fieldName, $fieldDefinition);
        }

        $output .= "}\n\n";
        return $output;
    }

    /**
     * Parses a single field definition from the YAML schema.
     *
     * @param string $fieldName
     * @param array|string $fieldDefinition
     * @return string
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
     *
     * @param string|null $laravelType
     * @return string
     */
    protected function mapFieldType(?string $laravelType): string
    {
        return match ($laravelType) {
            'foreignId' => 'integer',
            'string' => 'string',
            'text' => 'text',
            'boolean' => 'boolean',
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
     *
     * @param string $tableName
     * @param array $tableDefinition
     * @return string
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
     *
     * @param string $fromTable
     * @param string $relationName
     * @param array $relation
     * @return string
     */
    protected function parseRelation(string $fromTable, string $relationName, array $relation): string
    {
        $columnOverrides = [
            'creator' => 'created_by',
            'updater' => 'updated_by',
        ];

        $foreignKey = $columnOverrides[$relationName] ?? ($relationName . '_id');
        $fromTableName = Str::snake(Str::pluralStudly($fromTable));

        if ($relation['type'] === 'belongsTo') {
            $targetModel = $relation['model'];
            $targetTableName = Str::snake(Str::pluralStudly($targetModel));
            return "Ref: $fromTableName.$foreignKey > $targetTableName.id\n";
        }

        return '';
    }
}