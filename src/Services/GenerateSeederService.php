<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;

class GenerateSeederService
{
    private GenerateModuleFromYaml $command;

    private StubPathResolverService $pathResolverService;

    public function __construct(GenerateModuleFromYaml $command)
    {
        $this->command = $command;
        $this->pathResolverService = new StubPathResolverService;
    }

    /**
     * Generate factory and seeder files
     */
    public function generateSeeder(string $modelName, array $fields, bool $force = false): void
    {
        // Generate factory first
        $this->generateFactory($modelName, $fields, $force);

        // Then generate seeder
        $seederPath = database_path("seeders/{$modelName}Seeder.php");

        if (File::exists($seederPath) && ! $force) {
            $this->command->warn("⚠️ Seeder already exists: {$modelName}Seeder");

            return;
        }

        if (File::exists($seederPath)) {
            File::delete($seederPath);
            $this->command->warn("⚠️ Deleted existing seeder: {$modelName}Seeder");
        }

        $stubPath = $this->pathResolverService->resolveStubPath('seeder');

        if (! File::exists($stubPath)) {
            $this->command->error("Seeder stub not found: {$stubPath}");

            return;
        }

        $stubContent = File::get($stubPath);
        $seederContent = str_replace('{{ model }}', $modelName, $stubContent);

        File::put($seederPath, $seederContent);

        $this->command->info("🌱 Seeder created: {$modelName}Seeder");
    }

    /**
     * Generate factory file
     */
    private function generateFactory(string $modelName, array $fields, bool $force = false): void
    {
        $factoryPath = database_path("factories/{$modelName}Factory.php");

        if (File::exists($factoryPath) && ! $force) {
            $this->command->warn("⚠️ Factory already exists: {$modelName}Factory");

            return;
        }

        if (File::exists($factoryPath)) {
            File::delete($factoryPath);
            $this->command->warn("⚠️ Deleted existing factory: {$modelName}Factory");
        }

        $stubPath = $this->pathResolverService->resolveStubPath('factory');

        if (! File::exists($stubPath)) {
            $this->command->error("Factory stub not found: {$stubPath}");

            return;
        }

        $factoryFields = $this->buildFactoryFields($fields);

        $stubContent = File::get($stubPath);
        $factoryContent = str_replace([
            '{{ model }}',
            '{{ fields }}',
        ], [
            $modelName,
            $factoryFields,
        ], $stubContent);

        File::put($factoryPath, $factoryContent);

        $this->command->info("🏭 Factory created: {$modelName}Factory");
    }

    /**
     * Build factory fields with faker methods
     */
    private function buildFactoryFields(array $fields): string
    {
        $factoryFields = [];

        foreach ($fields as $fieldName => $definition) {
            // Skip timestamps and id
            if (in_array($fieldName, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $parts = explode(':', $definition);
            $type = array_shift($parts);
            $isNullable = in_array('nullable', $parts);

            $fakerMethod = $this->getFakerMethod($fieldName, $type, $parts);

            if ($isNullable && rand(0, 1)) {
                $factoryFields[] = "'{$fieldName}' => null";
            } else {
                $factoryFields[] = "'{$fieldName}' => {$fakerMethod}";
            }
        }

        if (empty($factoryFields)) {
            return '';
        }

        return '            '.implode(",\n            ", $factoryFields);
    }

    /**
     * Get appropriate faker method for field type
     */
    private function getFakerMethod(string $fieldName, string $type, array $modifiers): string
    {
        // Check for specific field names first
        if (str_contains($fieldName, 'email')) {
            return '$this->faker->unique()->safeEmail()';
        }
        if (str_contains($fieldName, 'phone')) {
            return '$this->faker->phoneNumber()';
        }
        if (str_contains($fieldName, 'url') || str_contains($fieldName, 'website')) {
            return '$this->faker->url()';
        }
        if (str_contains($fieldName, 'address')) {
            return '$this->faker->address()';
        }
        if (str_contains($fieldName, 'city')) {
            return '$this->faker->city()';
        }
        if (str_contains($fieldName, 'state')) {
            return '$this->faker->state()';
        }
        if (str_contains($fieldName, 'country')) {
            return '$this->faker->country()';
        }
        if (str_contains($fieldName, 'postal') || str_contains($fieldName, 'zip')) {
            return '$this->faker->postcode()';
        }
        if (str_contains($fieldName, 'name')) {
            return '$this->faker->name()';
        }
        if (str_contains($fieldName, 'title')) {
            return '$this->faker->sentence(3)';
        }
        if (str_contains($fieldName, 'slug')) {
            return '$this->faker->unique()->slug()';
        }
        if (str_contains($fieldName, 'description') || str_contains($fieldName, 'comment')) {
            return '$this->faker->paragraph()';
        }
        if (str_contains($fieldName, 'color')) {
            return '$this->faker->hexColor()';
        }

        // Handle foreignId specially
        if ($type === 'foreignId') {
            if (! empty($modifiers[0])) {
                $relatedTable = $modifiers[0];
                $modelName = Str::studly(Str::singular($relatedTable));

                return "\\App\\Models\\{$modelName}::factory()";
            }

            return '1';
        }

        // Then check by type
        return match ($type) {
            'string' => '$this->faker->word()',
            'text' => '$this->faker->paragraph()',
            'integer' => '$this->faker->numberBetween(1, 100)',
            'boolean' => '$this->faker->boolean()',
            'date' => '$this->faker->date()',
            'dateTime', 'timestamp' => '$this->faker->dateTime()',
            'double', 'decimal' => '$this->faker->randomFloat(2, 0, 1000)',
            'json' => '[]',
            'image', 'file' => 'null',
            default => '$this->faker->word()',
        };
    }
}
