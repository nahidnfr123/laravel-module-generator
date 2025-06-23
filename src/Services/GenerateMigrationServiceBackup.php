<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateMigrationServiceBackup
{
    private GenerateModuleFromYaml $command;

    public function __construct(GenerateModuleFromYaml $command)
    {
        $this->command = $command;
    }

    /**
     * Generate migration file with field definitions
     */
    public function generateMigration(string $modelName, array $fields, array $uniqueConstraints = []): void
    {
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $migrationFiles = glob(database_path('migrations/*create_'.$tableName.'_table.php'));

        if (empty($migrationFiles)) {
            $this->command->warn("Migration file not found for $modelName.");

            return;
        }

        $migrationFile = $migrationFiles[0];
        $fieldStub = $this->buildMigrationFields($fields, $uniqueConstraints);

        $this->updateMigrationFile($migrationFile, $fieldStub);

        $this->command->info("✅ Migration file updated for $modelName");
    }

    /**
     * Build field definitions for migration
     */
    private function buildMigrationFields(array $fields, array $uniqueConstraints): string
    {
        $fieldStub = '';

        foreach ($fields as $name => $definition) {
            $fieldStub .= $this->buildSingleFieldDefinition($name, $definition).";\n            ";
        }

        $fieldStub .= $this->buildUniqueConstraints($uniqueConstraints);

        return $fieldStub;
    }

    /**
     * Build a single field definition for migration
     */
    private function buildSingleFieldDefinition(string $name, string $definition): string
    {
        $parts = explode(':', $definition);
        $type = array_shift($parts);

        if ($type === 'foreignId') {
            return $this->buildForeignIdField($name, $parts);
        }

        return $this->buildRegularField($name, $type, $parts);
    }

    /**
     * Build foreign ID field definition
     */
    private function buildForeignIdField(string $name, array $parts): string
    {
        $references = array_shift($parts);
        $modifiers = $parts;

        $line = "\$table->foreignId('$name')";

        foreach ($modifiers as $modifier) {
            if (str_starts_with($modifier, 'default(')) {
                $line .= "->{$modifier}";
            } else {
                $line .= "->$modifier()";
            }
        }

        return $line."->constrained('$references')->cascadeOnDelete()";
    }

    /**
     * Build regular field definition
     */
    private function buildRegularField(string $name, string $type, array $parts): string
    {
        $line = "\$table->$type('$name')";

        foreach ($parts as $modifier) {
            $line .= $this->processFieldModifier($modifier);
        }

        return $line;
    }

    /**
     * Process individual field modifier
     */
    private function processFieldModifier(string $modifier): string
    {
        if (str_starts_with($modifier, 'default(')) {
            return "->{$modifier}";
        }

        if (str_starts_with($modifier, 'default')) {
            return $this->processDefaultModifier($modifier);
        }

        if (in_array($modifier, ['nullable', 'unique'])) {
            return "->$modifier()";
        }

        return '';
    }

    /**
     * Process default modifier with value
     */
    private function processDefaultModifier(string $modifier): string
    {
        $value = trim(str_replace('default', '', $modifier), ':');
        $value = trim($value);

        if (strtolower($value) === 'null') {
            return '->default(null)';
        }

        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return '->default('.$value.')';
        }

        if (is_numeric($value)) {
            return "->default($value)";
        }

        $value = trim($value, "'\"");

        return "->default('$value')";
    }

    /**
     * Build unique constraints for migration
     */
    private function buildUniqueConstraints(array $uniqueConstraints): string
    {
        $constraintStub = '';

        foreach ($uniqueConstraints as $columns) {
            if (is_array($columns)) {
                $cols = implode("', '", $columns);
                $constraintStub .= "\$table->unique(['$cols']);\n            ";
            } elseif (is_string($columns)) {
                $constraintStub .= "\$table->unique('$columns');\n            ";
            }
        }

        return $constraintStub;
    }

    /**
     * Update migration file with field definitions
     */
    private function updateMigrationFile(string $migrationFile, string $fieldStub): void
    {
        $migrationContent = file_get_contents($migrationFile);

        $migrationContent = preg_replace_callback(
            '/Schema::create\([^)]+function\s*\(Blueprint\s*\$table\)\s*{(.*?)(\$table->id\(\);)/s',
            function ($matches) use ($fieldStub) {
                return str_replace(
                    $matches[2],
                    $matches[2]."\n            ".$fieldStub,
                    $matches[0]
                );
            },
            $migrationContent
        );

        file_put_contents($migrationFile, $migrationContent);
    }
}
