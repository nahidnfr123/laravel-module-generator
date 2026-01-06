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
     * Generate or update migration file with field definitions
     */
    public function generateMigration(string $modelName, array $fields, array $uniqueConstraints = []): void
    {
        $tableName = Str::snake(Str::pluralStudly($modelName));
        $migrationPath = $this->getMigrationPath($tableName);

        // Check if migration file exists
        if ($migrationPath && File::exists($migrationPath)) {
            $this->updateExistingMigration($migrationPath, $tableName, $fields, $uniqueConstraints);
            $this->command->info("✅ Migration file updated for $modelName");
        } else {
            $this->createNewMigration($modelName, $tableName, $fields, $uniqueConstraints);
            $this->command->info("✅ Migration file created for $modelName");
        }
    }

    /**
     * Get migration file path if it exists
     */
    private function getMigrationPath(string $tableName): ?string
    {
        $migrationFiles = glob(database_path("migrations/*create_{$tableName}_table.php"));

        return ! empty($migrationFiles) ? $migrationFiles[0] : null;
    }

    public function findStubContent(): string
    {
        try {
            // Use stub from StubPathResolverService
            $stubPath = $this->stubPathResolver->resolveStubPath('migration');
            $stubContent = File::get($stubPath);
        } catch (\Exception $e) {
            // Fallback to inline stub if resolver fails
            $stubContent = $this->getDefaultMigrationStub();
            $this->command->warn('Using fallback migration stub: '.$e->getMessage());
        }

        return $stubContent;
    }

    /**
     * Create a new migration file using the stub
     */
    private function createNewMigration(string $modelName, string $tableName, array $fields, array $uniqueConstraints): void
    {
        $stubContent = $this->findStubContent();
        $migrationContent = $this->replaceMigrationPlaceholders($stubContent, $tableName, $fields, $uniqueConstraints);

        // Generate migration filename with timestamp
        $timestamp = date('Y_m_d_His');
        $migrationFileName = "{$timestamp}_create_{$tableName}_table.php";
        $migrationPath = database_path("migrations/{$migrationFileName}");

        File::put($migrationPath, $migrationContent);
    }

    /**
     * Update existing migration file
     */
    private function updateExistingMigration(string $migrationPath, string $tableName, array $fields, array $uniqueConstraints): void
    {
        $stubContent = $this->findStubContent();
        $migrationContent = $this->replaceMigrationPlaceholders($stubContent, $tableName, $fields, $uniqueConstraints);

        File::put($migrationPath, $migrationContent);
    }

    /**
     * Replace placeholders in migration stub
     */
    private function replaceMigrationPlaceholders(string $stubContent, string $tableName, array $fields, array $uniqueConstraints): string
    {
        $columnsStub = $this->buildMigrationColumns($fields, $uniqueConstraints);

        return str_replace([
            '{{ table }}',
            '{{ columns }}',
        ], [
            $tableName,
            $columnsStub,
        ], $stubContent);
    }

    /**
     * Build column definitions for migration
     */
    private function buildMigrationColumns(array $fields, array $uniqueConstraints): string
    {
        $columnsStub = '';

        foreach ($fields as $name => $definition) {
            $columnsStub .= $this->buildSingleColumnDefinition($name, $definition);
        }

        $columnsStub .= $this->buildUniqueConstraints($uniqueConstraints);

        return rtrim($columnsStub);
    }

    /**
     * Map custom field types to Laravel migration column types
     */
    private function mapFieldType(string $type): string
    {
        $typeMapping = [
            'image' => 'string',
            'file' => 'string',
            // Add more custom mappings as needed
        ];

        return $typeMapping[$type] ?? $type;
    }

    /**
     * Build a single column definition for migration
     */
    private function buildSingleColumnDefinition(string $name, string $definition): string
    {
        $parts = explode(':', $definition);
        $type = array_shift($parts);

        if ($type === 'foreignId') {
            return $this->buildForeignIdColumn($name, $parts);
        }

        // Map custom types to Laravel migration types
        $type = $this->mapFieldType($type);

        return $this->buildRegularColumn($name, $type, $parts);
    }

    /**
     * Build foreign ID column definition
     */
    private function buildForeignIdColumn(string $name, array $parts): string
    {
        $references = array_shift($parts);
        $modifiers = $parts;

        $line = "\$table->foreignId('$name')";

        foreach ($modifiers as $modifier) {
            $line .= $this->processColumnModifier($modifier);
        }

        $line .= "->constrained('$references')->cascadeOnDelete()";

        return "            {$line};\n";
    }

    /**
     * Build regular column definition
     */
    private function buildRegularColumn(string $name, string $type, array $parts): string
    {
        $line = "\$table->$type('$name')";

        foreach ($parts as $modifier) {
            $line .= $this->processColumnModifier($modifier);
        }

        return "            {$line};\n";
    }

    /**
     * Process individual column modifier
     */
    private function processColumnModifier(string $modifier): string
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
        if (empty($uniqueConstraints)) {
            return '';
        }

        $constraintStub = '';

        foreach ($uniqueConstraints as $columns) {
            if (is_array($columns)) {
                $cols = implode("', '", $columns);
                $constraintStub .= "            \$table->unique(['$cols']);\n";
            } elseif (is_string($columns)) {
                $constraintStub .= "            \$table->unique('$columns');\n";
            }
        }

        return $constraintStub;
    }

    /**
     * Get default migration stub as fallback
     */
    private function getDefaultMigrationStub(): string
    {
        return '<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(\'{{ table }}\', function (Blueprint $table) {
            $table->id();
{{ columns }}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(\'{{ table }}\');
    }
};';
    }
}