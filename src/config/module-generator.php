<?php

return [
    'api' => true,
    'base_path' => base_path('module'),
    'models_path' => base_path('module/models.yaml'),
    'model_style' => 'attributes', // 'attributes' (Laravel 13+) or 'traditional' (pre-Laravel 13)
    'stubs' => [
        'model' => 'model.stub',
        'model-attribute' => 'model-attribute.stub',
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
        'default_base_url' => config('app.url'),
        'default_prefix' => 'api',
        'output_path' => 'module/postman_collection.json',
    ],
    'backup_path' => storage_path('app/module-backups'),
    'dbdiagram' => [
        'output_path' => 'module/dbdiagram.dbml',
    ],
];
