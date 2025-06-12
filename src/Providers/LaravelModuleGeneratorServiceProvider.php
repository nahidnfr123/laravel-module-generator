<?php

namespace NahidFerdous\LaravelModuleGenerator\Providers;

use Illuminate\Support\ServiceProvider;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateDbDiagram;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromModelName;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GenerateModuleFromYaml;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\GeneratePostmanCollection;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\Install;
use NahidFerdous\LaravelModuleGenerator\Console\Commands\ModuleRollback;

class LaravelModuleGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/module-generator.php', 'module-generator');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Install::class,
                GenerateModuleFromModelName::class,
                GenerateModuleFromYaml::class,
                GeneratePostmanCollection::class,
                GenerateDbDiagram::class,
                ModuleRollback::class,
            ]);

            // Publish stubs separately
            $this->publishes([
                __DIR__ . '/../stubs' => base_path('module/stubs'),
            ], 'module-generator-stubs');

            // Publish config separately
            $this->publishes([
                __DIR__ . '/../config/module-generator.php' => config_path('module-generator.php'),
            ], 'module-generator-config');

            // Publish both together with general tag
            $this->publishes([
                __DIR__ . '/../stubs' => base_path('module/stubs'),
                __DIR__ . '/../config/module-generator.php' => config_path('module-generator.php'),
            ], 'module-generator');
        }
    }
}
