<?php

namespace NahidFerdous\LaravelModuleGenerator\Tests\Unit\Services;

use NahidFerdous\LaravelModuleGenerator\Services\StubPathResolverService;
use NahidFerdous\LaravelModuleGenerator\Tests\TestCase;

class StubPathResolverServiceTest extends TestCase
{
    private StubPathResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StubPathResolverService();
    }

    public function test_it_throws_exception_when_config_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Module generator stubs configuration not found.');

        config(['module-generator' => []]);

        $this->service->resolveStubPath('model');
    }

    public function test_it_throws_exception_for_undefined_stub_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stub not defined for key: nonexistent');

        $this->service->resolveStubPath('nonexistent');
    }

    public function test_it_returns_fallback_path_when_published_path_does_not_exist(): void
    {
        $path = $this->service->resolveStubPath('model');

        $this->assertStringContainsString('model.stub', $path);
        $this->assertFileExists($path);
    }

    public function test_it_returns_published_path_when_it_exists(): void
    {
        $publishedDir = config('module-generator.base_path').'/stubs';
        $publishedStub = $publishedDir.'/model.stub';

        if (! is_dir($publishedDir)) {
            mkdir($publishedDir, 0777, true);
        }
        file_put_contents($publishedStub, 'published');

        $path = $this->service->resolveStubPath('model');

        $this->assertSame($publishedStub, $path);

        unlink($publishedStub);
        rmdir($publishedDir);
    }

    public function test_it_throws_exception_when_fallback_file_missing(): void
    {
        $this->expectException(\RuntimeException::class);

        $path = $this->service->resolveStubPath('repository');
    }

    public function test_it_resolves_controller_stub(): void
    {
        $path = $this->service->resolveStubPath('controller');

        $this->assertStringContainsString('controller.stub', $path);
        $this->assertFileExists($path);
    }

    public function test_it_resolves_all_defined_stubs(): void
    {
        $stubs = ['model', 'controller', 'service', 'migration', 'request', 'collection', 'resource', 'factory', 'seeder'];

        foreach ($stubs as $key) {
            $path = $this->service->resolveStubPath($key);
            $this->assertStringContainsString($key.'.stub', $path);
            $this->assertFileExists($path);
        }
    }
}
