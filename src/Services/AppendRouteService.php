<?php

namespace NahidFerdous\LaravelModuleGenerator\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AppendRouteService
{
    private Command $command;

    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    public function appendRoute(string $tableName, string $controllerClass): void
    {
        $routeLine = "Route::apiResource('{$tableName}', \\App\\Http\\Controllers\\{$controllerClass}::class);";
        $apiRoutesPath = base_path('routes/api.php');

        // Check if the api.php file exists, create it if it doesn't
        if (! File::exists($apiRoutesPath)) {
            // Create the routes directory if it doesn't exist
            $routesDirectory = dirname($apiRoutesPath);
            if (! File::exists($routesDirectory)) {
                File::makeDirectory($routesDirectory, 0755, true);
            }

            // Create a basic api.php file with the standard Laravel structure
            $defaultApiContent = "<?php\n\nuse Illuminate\Http\Request;\nuse Illuminate\Support\Facades\Route;\n\n/*\n|--------------------------------------------------------------------------\n| API Routes\n|--------------------------------------------------------------------------\n|\n| Here is where you can register API routes for your application. These\n| routes are loaded by the RouteServiceProvider and all of them will\n| be assigned to the \"api\" middleware group. Make something great!\n|\n*/\n\nRoute::get('/user', function (Request \$request) {\n    return \$request->user();\n})->middleware('auth:sanctum');\n";

            File::put($apiRoutesPath, $defaultApiContent);
            $this->command->info('📁 Created routes/api.php file.');
        }

        // Now check if the route already exists
        if (! Str::contains(File::get($apiRoutesPath), $routeLine)) {
            File::append($apiRoutesPath, "\n{$routeLine}\n");
            $this->command->info('🤫 API route added.');
        } else {
            $this->command->warn("⚠️ Route Already Exists: {$routeLine}");
        }
    }

    /**
     * Append API route to routes file
     */
    //    protected function appendRoute(string $tableName, string $controllerClass): void
    //    {
    //        $routeLine = "Route::apiResource('{$tableName}', \\App\\Http\\Controllers\\{$controllerClass}::class);";
    //        $apiRoutesPath = base_path('routes/api.php');
    //
    //        if (!Str::contains(File::get($apiRoutesPath), $routeLine)) {
    //            File::append($apiRoutesPath, "\n{$routeLine}\n");
    //            $this->info('🤫 API route added.');
    //        } else {
    //            $this->warn("⚠️ Route Already Exists: {$routeLine}");
    //        }
    //    }
}
