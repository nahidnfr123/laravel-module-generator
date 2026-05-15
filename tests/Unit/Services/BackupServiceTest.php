<?php

namespace NahidFerdous\LaravelModuleGenerator\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use NahidFerdous\LaravelModuleGenerator\Contracts\OutputInterface;
use NahidFerdous\LaravelModuleGenerator\Services\BackupService;
use NahidFerdous\LaravelModuleGenerator\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class BackupServiceTest extends TestCase
{
    private OutputInterface&MockObject $command;
    private BackupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = $this->createMock(OutputInterface::class);
        $this->service = new BackupService($this->command);
    }

    public function test_get_latest_backup_path_returns_null_when_no_backups(): void
    {
        $this->assertNull($this->service->getLatestBackupPath());
    }

    public function test_list_backups_returns_empty_when_no_backups(): void
    {
        $this->assertSame([], $this->service->listBackups());
    }

    public function test_load_backup_manifest_returns_null_when_no_backups(): void
    {
        $this->assertNull($this->service->loadBackupManifest());
    }

    public function test_create_backup_with_no_existing_files(): void
    {
        $this->command->method('info');

        $backupPath = $this->service->createBackup(['Product' => ['fields' => ['name' => 'string']]]);

        $this->assertNotNull($backupPath);
        $this->assertStringContainsString('module-backups', $backupPath);
        $this->assertFileExists($backupPath.'/backup_manifest.json');

        $manifest = json_decode(File::get($backupPath.'/backup_manifest.json'), true);

        $this->assertArrayHasKey('timestamp', $manifest);
        $this->assertArrayHasKey('models', $manifest);
        $this->assertArrayHasKey('Product', $manifest['models']);

        // Cleanup
        File::deleteDirectory(dirname($backupPath));
    }

    public function test_create_backup_with_existing_files(): void
    {
        // Create a fake model file
        $modelDir = app_path('Models');
        File::ensureDirectoryExists($modelDir);
        File::put($modelDir.'/Brand.php', '<?php // Brand model');

        $this->command->method('info');

        $backupPath = $this->service->createBackup(['Brand' => ['fields' => ['name' => 'string']]]);

        $this->assertNotNull($backupPath);
        $this->assertFileExists($backupPath.'/backup_manifest.json');

        $manifest = json_decode(File::get($backupPath.'/backup_manifest.json'), true);

        $this->assertTrue($manifest['models']['Brand']['model']['backed_up']);

        // Cleanup
        File::delete($modelDir.'/Brand.php');
        File::deleteDirectory(dirname($backupPath));
    }

    public function test_cleanup_old_backups(): void
    {
        // Create old backup directories
        $backupDir = config('module-generator.backup_path');
        File::ensureDirectoryExists($backupDir.'/2021-01-01_00-00-00');
        File::ensureDirectoryExists($backupDir.'/2021-01-02_00-00-00');
        File::ensureDirectoryExists($backupDir.'/2021-01-03_00-00-00');

        $this->command->method('info');

        $deleted = $this->service->cleanupOldBackups(2);

        $this->assertSame(1, $deleted);

        // Cleanup
        File::deleteDirectory($backupDir);
    }

    public function test_cleanup_old_backups_within_limit(): void
    {
        $backupDir = config('module-generator.backup_path');
        File::ensureDirectoryExists($backupDir.'/2021-01-01_00-00-00');

        $deleted = $this->service->cleanupOldBackups(5);

        $this->assertSame(0, $deleted);

        // Cleanup
        File::deleteDirectory($backupDir);
    }

    public function test_get_latest_backup_path_returns_most_recent(): void
    {
        $backupDir = config('module-generator.backup_path');
        File::ensureDirectoryExists($backupDir.'/2021-01-01_00-00-00');
        File::ensureDirectoryExists($backupDir.'/2021-01-03_00-00-00');
        File::ensureDirectoryExists($backupDir.'/2021-01-02_00-00-00');

        $latest = $this->service->getLatestBackupPath();

        $this->assertStringContainsString('2021-01-03_00-00-00', $latest);

        // Cleanup
        File::deleteDirectory($backupDir);
    }
}
