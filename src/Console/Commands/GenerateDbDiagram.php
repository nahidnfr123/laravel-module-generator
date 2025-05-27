<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class GenerateDbDiagram extends Command
{
    protected $signature = 'dbdiagram:generate {--file=module/models.yaml : Path to the YAML schema file} {--output=module/dbdiagram.dbml}';
    protected $description = 'Generate dbdiagram.io syntax (DBML) from YAML schema';

    public function handle()
    {
        $path = $this->option('file');
        if (!$path) {
            $path = config('module-generator.models_path', 'module/models.yaml');
        }
        if (!file_exists($path)) {
            $this->error("File not found: $path");
            return Command::FAILURE;
        }

        $schema = Yaml::parseFile($path);
        $output = '';

        foreach ($schema as $tableName => $tableDef)
        {
            $targetTable = Str::snake(Str::pluralStudly($tableName));
            $output .= "Table $targetTable {\n";
            $output .= "  id integer [primary key]\n"; // ✅ Add default primary key
            foreach ($tableDef['fields'] ?? [] as $fieldName => $fieldDef) {
                $output .= $this->parseField($fieldName, $fieldDef);
            }
            $output .= "}\n\n";
        }

        foreach ($schema as $tableName => $tableDef) {
            foreach (($tableDef['relations'] ?? []) as $relationName => $rel) {
                $output .= $this->parseRelation($tableName, $relationName, $rel);
            }
        }

        file_put_contents($this->option('output'), $output);
        $this->info("DB diagram generated at: " . $this->option('output'));
        return Command::SUCCESS;
    }

    protected function parseField($fieldName, $fieldDef)
    {
        // parse type
        [$type, $modifiers] = explode(':', $fieldDef, 2) + [null, null];
        $typeMap = [
            'foreignId' => 'integer',
            'string' => 'string',
            'text' => 'text',
            'boolean' => 'boolean',
            'double' => 'double',
            'decimal' => 'decimal',
            'date' => 'date',
            'dateTime' => 'datetime',
            'softDeletes' => 'datetime',
        ];
        $type = $typeMap[$type] ?? 'string';

        $note = '';
        if (str_contains($modifiers, 'nullable')) {
            $note .= ' [note: "nullable"]';
        }

        return "  $fieldName $type$note\n";
    }

    protected function parseRelation($table, $relationName, $relation)
    {
        $columnOverrides = [
            'creator' => 'created_by',
            'updater' => 'updated_by',
        ];

        $foreignKey = $columnOverrides[$relationName] ?? ($relationName . '_id');

        if ($relation['type'] === 'belongsTo') {
            $targetModel = $relation['model'];
            $targetTable = Str::snake(Str::pluralStudly($targetModel)); // e.g. User → users
            $rename_table = Str::snake(Str::pluralStudly($table)); // e.g. User → users

            return "Ref: $rename_table.$foreignKey > $targetTable.id\n";
        }

        return '';
    }
}
