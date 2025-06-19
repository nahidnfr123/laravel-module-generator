<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateMigrationService
{
    private GenerateModuleFromYaml $command;
    private StubPathResolverService $stubPathResolver;

    public function __construct(GenerateModuleFromYaml $command)
    {
        $this->command = $command;
        $this->stubPathResolver = new StubPathResolverService;
    }

    /**
     * Generate migration file with field definitions
     */
    public function generateMigration(string $modelName, array $fields, array $uniqueConstraints = []): void
    {
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $migrationFiles = glob(database_path('migrations/*create_'.$tableName.'_table.php'));

        if (empty($migrationFiles)) {
            // Create new migration file if it doesn't exist
            $this->createMigrationFile($modelName, $tableName, $fields, $uniqueConstraints);
        } else {
            // Replace existing migration file completely to avoid duplicates
            $migrationFile = $migrationFiles[0];
            $this->replaceMigrationFile($migrationFile, $tableName, $fields, $uniqueConstraints);
        }

        $this->command->info("✅ Migration file processed for $modelName");
    }

    /**
     * Create new migration file using stub
     */
    private function createMigrationFile(string $modelName, string $tableName, array $fields, array $uniqueConstraints): void
    {
        try {
            $stubPath = $this->stubPathResolver->resolveStubPath('migration');
            $stubContent = File::get($stubPath);

            $fieldStub = $this->buildMigrationFields($fields, $uniqueConstraints);

            // Remove trailing whitespace and semicolon from fieldStub for cleaner output
            $fieldStub = rtrim($fieldStub);

            $migrationContent = str_replace([
                '{{ table }}',
                '{{ columns }}',
            ], [
                $tableName,
                $fieldStub,
            ], $stubContent);

            // Generate migration filename with timestamp
            $timestamp = date('Y_m_d_His');
            $migrationFileName = "{$timestamp}_create_{$tableName}_table.php";
            $migrationPath = database_path("migrations/{$migrationFileName}");

            // Create migrations directory if it doesn't exist
            $migrationsDir = database_path('migrations');
            if (!File::exists($migrationsDir)) {
                File::makeDirectory($migrationsDir, 0755, true);
            }

            File::put($migrationPath, $migrationContent);

            $this->command->info("🆕 Created new migration file: {$migrationFileName}");

        } catch (\Exception $e) {
            $this->command->error('Failed to create migration using stub: ' . $e->getMessage());

            // Fallback to Laravel's artisan command
            $this->createMigrationFallback($tableName);
        }
    }

    /**
     * Fallback method to create migration using Laravel's artisan command
     */
    private function createMigrationFallback(string $tableName): void
    {
        $migrationName = "create_{$tableName}_table";

        try {
            \Illuminate\Support\Facades\Artisan::call('make:migration', [
                'name' => $migrationName,
                '--create' => $tableName
            ]);

            $this->command->info("🔄 Created migration using fallback method: {$migrationName}");
        } catch (\Exception $e) {
            $this->command->error("Failed to create migration: " . $e->getMessage());
        }
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

        return rtrim($fieldStub);
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
     * Replace existing migration file completely using stub
     */
    private function replaceMigrationFile(string $migrationFile, string $tableName, array $fields, array $uniqueConstraints): void
    {
        try {
            $stubPath = $this->stubPathResolver->resolveStubPath('migration');
            $stubContent = File::get($stubPath);

            $fieldStub = $this->buildMigrationFields($fields, $uniqueConstraints);

            // Remove trailing whitespace and semicolon from fieldStub for cleaner output
            $fieldStub = rtrim($fieldStub);

            $migrationContent = str_replace([
                '{{ table }}',
                '{{ columns }}',
            ], [
                $tableName,
                $fieldStub,
            ], $stubContent);

            File::put($migrationFile, $migrationContent);

            $this->command->info("🔄 Replaced existing migration file with updated content");

        } catch (\Exception $e) {
            $this->command->error('Failed to replace migration using stub: ' . $e->getMessage());

            // Fallback to the original update method
            $fieldStub = $this->buildMigrationFields($fields, $uniqueConstraints);
            $this->updateMigrationFile($migrationFile, $fieldStub);
        }
    }
}