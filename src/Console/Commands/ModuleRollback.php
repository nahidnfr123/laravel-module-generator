<?php

namespace NahidFerdous\LaravelModuleGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use NahidFerdous\LaravelModuleGenerator\Contracts\OutputInterface;
use NahidFerdous\LaravelModuleGenerator\Services\BackupService;


class ModuleRollback extends Command implements OutputInterface
{
    protected $signature = 'module:rollback
                           {--backup= : Specific backup timestamp to rollback to}
                           {--list : List available backups}
                           {--cleanup : Clean up old backups}';

    protected $description = 'Rollback module generation by restoring from backup';

    private BackupService $backupService;

    public function handle(): int
    {
        $this->backupService = new BackupService($this);

        if ($this->option('list')) {
            return $this->listBackups();
        }

        if ($this->option('cleanup')) {
            return $this->cleanupBackups();
        }

        return $this->performRollback();
    }

    /**
     * List available backups
     */
    private function listBackups(): int
    {
        $backups = $this->backupService->listBackups();

        if (empty($backups)) {
            $this->info('No backups found.');

            return self::SUCCESS;
        }

        $this->info('Available backups:');
        $this->newLine();

        $headers = ['Timestamp', 'Models', 'Files', 'Size'];
        $rows = [];

        foreach ($backups as $backup) {
            $rows[] = [
                $backup['timestamp'],
                $backup['models_count'] ?? 'N/A',
                $backup['files_backed_up'] ?? 'N/A',
                $this->formatBytes($backup['size']),
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Clean up old backups
     */
    private function cleanupBackups(): int
    {
        $keepCount = (int) $this->ask('How many recent backups to keep?', '2');

        if ($keepCount < 0) {
            $this->error('Must keep at least 0 backup.');

            return self::FAILURE;
        }

        $deleted = $this->backupService->cleanupOldBackups($keepCount);

        if ($deleted > 0) {
            $this->info("🗑️ Cleaned up {$deleted} old backup(s).");
        } else {
            $this->info('No old backups to clean up.');
        }

        return self::SUCCESS;
    }

    /**
     * Perform the rollback operation
     */
    private function performRollback(): int
    {
        $backupPath = $this->option('backup');

        if (! $backupPath) {
            $backupPath = $this->backupService->getLatestBackupPath();

            if (! $backupPath) {
                $this->error('No backups found. Cannot rollback.');

                return self::FAILURE;
            }

            $timestamp = basename($backupPath);
            if (! $this->confirm("Rollback to latest backup ({$timestamp})?")) {
                $this->info('Rollback cancelled.');

                return self::SUCCESS;
            }
        }

        $manifest = $this->backupService->loadBackupManifest($backupPath);

        if (! $manifest) {
            $this->error('Invalid backup or missing manifest file.');

            return self::FAILURE;
        }

        $this->info('🔄 Starting rollback process...');

        $restored = $this->restoreFiles($manifest);
        $deleted = $this->removeGeneratedFiles($manifest);

        $this->info('✅ Rollback completed!');
        $this->info("📦 Restored {$restored} files");
        $this->info("🗑️ Removed {$deleted} generated files");

        return self::SUCCESS;
    }

    /**
     * Restore backed up files
     */
    private function restoreFiles(array $manifest): int
    {
        $restoredCount = 0;

        // Restore model files
        foreach ($manifest['models'] as $modelName => $modelFiles) {
            foreach ($modelFiles as $fileType => $fileInfo) {
                if ($fileInfo['backed_up'] && $fileInfo['backup_path']) {
                    try {
                        $originalPath = $fileInfo['original_path'];
                        File::ensureDirectoryExists(dirname($originalPath));
                        File::copy($fileInfo['backup_path'], $originalPath);

                        $this->info("📦 Restored {$fileType}: {$modelName}");
                        $restoredCount++;

                    } catch (\Exception $e) {
                        $this->warn("⚠️ Failed to restore {$fileType} for {$modelName}: ".$e->getMessage());
                    }
                }
            }
        }

        $isApi = config('module-generator.api');
        // Restore routes file
        if ($manifest['routes_backup']) {
            try {
                if ($isApi) {
                    File::copy($manifest['routes_backup'], base_path('routes/api.php'));
                    $this->info('📦 Restored routes/api.php');
                } else {
                    File::copy($manifest['routes_backup'], base_path('routes/web.php'));
                    $this->info('📦 Restored routes/web.php');
                }
                $restoredCount++;
            } catch (\Exception $e) {
                $this->warn('⚠️ Failed to restore routes: '.$e->getMessage());
            }
        }

        return $restoredCount;
    }

    /**
     * Remove files that were generated (and not backed up)
     */
    private function removeGeneratedFiles(array $manifest): int
    {
        $deletedCount = 0;

        foreach ($manifest['models'] as $modelName => $modelFiles) {
            foreach ($modelFiles as $fileType => $fileInfo) {
                // If file exists now but wasn't backed up, it was generated
                if (! $fileInfo['backed_up'] && $fileInfo['original_path'] && File::exists($fileInfo['original_path'])) {
                    try {
                        File::delete($fileInfo['original_path']);
                        $this->info("🗑️ Removed generated {$fileType}: {$modelName}");
                        $deletedCount++;
                    } catch (\Exception $e) {
                        $this->warn("⚠️ Failed to remove {$fileType} for {$modelName}: ".$e->getMessage());
                    }
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}
