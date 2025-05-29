<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BackupService
{
    private Command $command;

    private string $backupPath;

    private ?string $currentSessionPath = null;

    public function __construct(Command $command)
    {
        $this->command = $command;
        $this->backupPath = config('module-generator.backup_path', storage_path('app/backups'));
    }

    /**
     * Create backup for all models from YAML configuration
     */
    public function createBackup(array $models): string
    {
        $this->command->info('🔄 Starting backup process...');

        $timestamp = now()->format('Y-m-d_H-i-s');
        $this->currentSessionPath = "{$this->backupPath}/{$timestamp}";

        File::ensureDirectoryExists($this->currentSessionPath);

        $backupManifest = $this->initializeManifest($timestamp);
        $totalBackedUp = 0;

        foreach ($models as $modelName => $modelData) {
            $modelBackupInfo = $this->backupModelFiles($modelName);
            $backupManifest['models'][$modelName] = $modelBackupInfo;
            $totalBackedUp += count(array_filter($modelBackupInfo, fn ($file) => $file['backed_up']));
        }

        // Backup routes/api.php
        $backupManifest['routes_backup'] = $this->backupApiRoutes();

        // Save manifest
        $this->saveManifest($backupManifest);

        $this->command->info("✅ Backup completed! {$totalBackedUp} files backed up to: {$this->currentSessionPath}");
        $this->command->info('📋 Backup manifest saved for rollback functionality');

        return $this->currentSessionPath;
    }

    /**
     * Initialize backup manifest structure
     */
    private function initializeManifest(string $timestamp): array
    {
        return [
            'timestamp' => $timestamp,
            'models' => [],
            'routes_backup' => null,
            'generated_files' => [],
        ];
    }

    /**
     * Backup all files related to a specific model
     */
    private function backupModelFiles(string $modelName): array
    {
        $studlyName = Str::studly($modelName);
        $tableName = Str::snake(Str::plural($studlyName));

        $filesToBackup = $this->getModelFilePaths($studlyName, $tableName);
        $backupInfo = [];

        foreach ($filesToBackup as $fileType => $filePath) {
            $backupInfo[$fileType] = $this->backupSingleFile($filePath, $fileType, $studlyName);
        }

        return $backupInfo;
    }

    /**
     * Get all file paths for a model
     */
    private function getModelFilePaths(string $studlyName, string $tableName): array
    {
        return [
            'model' => app_path("Models/{$studlyName}.php"),
            'service' => app_path("Services/{$studlyName}Service.php"),
            'request' => app_path("Http/Requests/{$studlyName}Request.php"),
            'resource' => app_path("Http/Resources/{$studlyName}/{$studlyName}Resource.php"),
            'collection' => app_path("Http/Resources/{$studlyName}/{$studlyName}Collection.php"),
            'controller' => app_path("Http/Controllers/{$studlyName}Controller.php"),
            'migration' => $this->findMigrationFile($tableName),
        ];
    }

    /**
     * Find migration file for a table
     */
    private function findMigrationFile(string $tableName): ?string
    {
        $migrationFiles = File::glob(database_path("migrations/*_create_{$tableName}_table.php"));

        return ! empty($migrationFiles) ? $migrationFiles[0] : null;
    }

    /**
     * Backup a single file
     */
    private function backupSingleFile(?string $filePath, string $fileType, string $modelName): array
    {
        $backupInfo = [
            'original_path' => $filePath,
            'backup_path' => null,
            'backed_up' => false,
            'exists' => false,
            'error' => null,
        ];

        if (! $filePath || ! File::exists($filePath)) {
            return $backupInfo;
        }

        $backupInfo['exists'] = true;

        try {
            $backupPath = $this->generateBackupPath($fileType, $modelName, $filePath);
            File::ensureDirectoryExists(dirname($backupPath));
            File::copy($filePath, $backupPath);

            $backupInfo['backup_path'] = $backupPath;
            $backupInfo['backed_up'] = true;

            $this->command->info("📦 Backed up {$fileType}: {$modelName} → ".basename($backupPath));

        } catch (\Exception $e) {
            $backupInfo['error'] = $e->getMessage();
            $this->command->warn("⚠️ Failed to backup {$fileType} for {$modelName}: ".$e->getMessage());
        }

        return $backupInfo;
    }

    /**
     * Generate backup path based on file type
     */
    private function generateBackupPath(string $fileType, string $modelName, string $originalPath): string
    {
        $backupDir = match ($fileType) {
            'migration' => "{$this->currentSessionPath}/migrations",
            'resource', 'collection' => "{$this->currentSessionPath}/{$fileType}/{$modelName}",
            default => "{$this->currentSessionPath}/{$fileType}"
        };

        return "{$backupDir}/".basename($originalPath);
    }

    /**
     * Backup API routes file
     */
    private function backupApiRoutes(): ?string
    {
        $apiRoutesPath = base_path('routes/api.php');

        if (! File::exists($apiRoutesPath)) {
            return null;
        }

        $routesBackupPath = "{$this->currentSessionPath}/routes/api.php";
        File::ensureDirectoryExists(dirname($routesBackupPath));
        File::copy($apiRoutesPath, $routesBackupPath);

        $this->command->info('📄 Backed up routes/api.php');

        return $routesBackupPath;
    }

    /**
     * Save backup manifest
     */
    private function saveManifest(array $manifest): void
    {
        $manifestPath = "{$this->currentSessionPath}/backup_manifest.json";
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Get the latest backup session path
     */
    public function getLatestBackupPath(): ?string
    {
        if (! File::exists($this->backupPath)) {
            return null;
        }

        $directories = File::directories($this->backupPath);

        if (empty($directories)) {
            return null;
        }

        // Sort by timestamp (latest first)
        usort($directories, fn ($a, $b) => basename($b) <=> basename($a));

        return $directories[0];
    }

    /**
     * Load backup manifest
     */
    public function loadBackupManifest(?string $backupPath = null): ?array
    {
        $backupPath = $backupPath ?? $this->getLatestBackupPath();

        if (! $backupPath) {
            return null;
        }

        $manifestPath = "{$backupPath}/backup_manifest.json";

        if (! File::exists($manifestPath)) {
            return null;
        }

        try {
            return json_decode(File::get($manifestPath), true);
        } catch (\Exception $e) {
            $this->command->warn('⚠️ Failed to load backup manifest: '.$e->getMessage());

            return null;
        }
    }

    /**
     * List all available backups
     */
    public function listBackups(): array
    {
        if (! File::exists($this->backupPath)) {
            return [];
        }

        $directories = File::directories($this->backupPath);
        $backups = [];

        foreach ($directories as $dir) {
            $timestamp = basename($dir);
            $manifestPath = "{$dir}/backup_manifest.json";

            $backup = [
                'timestamp' => $timestamp,
                'path' => $dir,
                'has_manifest' => File::exists($manifestPath),
                'size' => $this->getDirectorySize($dir),
            ];

            if ($backup['has_manifest']) {
                $manifest = $this->loadBackupManifest($dir);
                $backup['models_count'] = count($manifest['models'] ?? []);
                $backup['files_backed_up'] = $this->countBackedUpFiles($manifest);
            }

            $backups[] = $backup;
        }

        // Sort by timestamp (latest first)
        usort($backups, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $backups;
    }

    /**
     * Get directory size in bytes
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Count total backed up files from manifest
     */
    private function countBackedUpFiles(array $manifest): int
    {
        $count = 0;

        foreach ($manifest['models'] ?? [] as $modelFiles) {
            foreach ($modelFiles as $fileInfo) {
                if ($fileInfo['backed_up'] ?? false) {
                    $count++;
                }
            }
        }

        // Add routes backup if exists
        if ($manifest['routes_backup']) {
            $count++;
        }

        return $count;
    }

    /**
     * Clean up old backups (keep only specified number)
     */
    public function cleanupOldBackups(int $keepCount = 5): int
    {
        $backups = $this->listBackups();

        if (count($backups) <= $keepCount) {
            return 0;
        }

        $toDelete = array_slice($backups, $keepCount);
        $deletedCount = 0;

        foreach ($toDelete as $backup) {
            try {
                File::deleteDirectory($backup['path']);
                $deletedCount++;
                $this->command->info("🗑️ Deleted old backup: {$backup['timestamp']}");
            } catch (\Exception $e) {
                $this->command->warn("⚠️ Failed to delete backup {$backup['timestamp']}: ".$e->getMessage());
            }
        }

        return $deletedCount;
    }
}
