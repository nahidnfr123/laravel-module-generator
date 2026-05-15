<?php

namespace NahidFerdous\LaravelModuleGenerator\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use NahidFerdous\LaravelModuleGenerator\Contracts\OutputInterface;
use NahidFerdous\LaravelModuleGenerator\Services\AppendRouteService;
use NahidFerdous\LaravelModuleGenerator\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AppendRouteServiceTest extends TestCase
{
    private OutputInterface&MockObject $command;
    private AppendRouteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = $this->createMock(OutputInterface::class);
        $this->service = new AppendRouteService($this->command);
    }

    public function test_it_creates_routes_file_if_not_exists(): void
    {
        $routesPath = base_path('routes/api.php');

        if (File::exists($routesPath)) {
            File::delete($routesPath);
        }

        $this->command->expects($this->atLeastOnce())->method('info');

        $this->service->appendRoute('products', 'ProductController');

        $this->assertFileExists($routesPath);
        $this->assertStringContainsString(
            "Route::apiResource('products', \\App\\Http\\Controllers\\ProductController::class);",
            File::get($routesPath)
        );

        File::delete($routesPath);
    }

    public function test_it_appends_route_to_existing_file(): void
    {
        $routesPath = base_path('routes/api.php');
        File::ensureDirectoryExists(dirname($routesPath));
        File::put($routesPath, "<?php\n\n");
        $this->command->method('info');

        $this->service->appendRoute('categories', 'CategoryController');

        $content = File::get($routesPath);
        $this->assertStringContainsString(
            "Route::apiResource('categories', \\App\\Http\\Controllers\\CategoryController::class);",
            $content
        );
    }

    public function test_it_does_not_duplicate_routes(): void
    {
        $routesPath = base_path('routes/api.php');
        File::ensureDirectoryExists(dirname($routesPath));

        $routeLine = "Route::apiResource('users', \\App\\Http\\Controllers\\UserController::class);";
        File::put($routesPath, "<?php\n\n{$routeLine}\n");

        $this->command->expects($this->once())->method('warn');

        $this->service->appendRoute('users', 'UserController');

        $this->assertEquals(1, substr_count(File::get($routesPath), $routeLine));
    }

    public function test_it_uses_web_routes_when_not_api(): void
    {
        config(['module-generator.api' => false]);

        $routesPath = base_path('routes/web.php');
        File::ensureDirectoryExists(dirname($routesPath));
        File::put($routesPath, "<?php\n\n");
        $this->command->method('info');

        $this->service->appendRoute('posts', 'PostController');

        $content = File::get($routesPath);
        $this->assertStringContainsString(
            "Route::resource('posts', \\App\\Http\\Controllers\\PostController::class);",
            $content
        );

        File::delete($routesPath);
        config(['module-generator.api' => true]);
    }

    protected function tearDown(): void
    {
        $routesApi = base_path('routes/api.php');
        $routesWeb = base_path('routes/web.php');

        if (File::exists($routesApi)) {
            File::delete($routesApi);
        }
        if (File::exists($routesWeb)) {
            File::delete($routesWeb);
        }

        parent::tearDown();
    }
}
