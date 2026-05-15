<?php

namespace NahidFerdous\LaravelModuleGenerator\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            \NahidFerdous\LaravelModuleGenerator\Providers\LaravelModuleGeneratorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('module-generator', [
            'api' => true,
            'base_path' => $app['path.base'].'/module',
            'models_path' => $app['path.base'].'/module/models.yaml',
            'stubs' => [
                'model' => 'model.stub',
                'controller' => 'controller.stub',
                'service' => 'service.stub',
                'repository' => 'repository.stub',
                'migration' => 'migration.stub',
                'request' => 'request.stub',
                'collection' => 'collection.stub',
                'resource' => 'resource.stub',
                'factory' => 'factory.stub',
                'seeder' => 'seeder.stub',
            ],
            'postman' => [
                'default_base_url' => 'http://localhost',
                'default_prefix' => 'api',
                'output_path' => 'module/postman_collection.json',
            ],
            'backup_path' => $app['path.storage'].'/app/module-backups',
            'dbdiagram' => [
                'output_path' => 'module/dbdiagram.dbml',
            ],
        ]);
    }
}
