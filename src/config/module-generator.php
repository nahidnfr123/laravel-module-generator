<?php

// module-generator.php

$randomNumber = rand(100, 999);

return [
    'api' => false,
    'base_path' => base_path('module'),
    'models_path' => base_path('module/models.yaml'),
    'stubs' => [
        'model' => 'model.stub',

        'controller' => 'controller.stub',
        'service' => 'service.stub',

        'repository' => 'repository.stub',
        'migration' => 'migration.stub',
        'request' => 'request.stub',
        'collection' => 'collection.stub',
        'resource' => 'resource.stub',
    ],
    // Postman collection settings
    'postman' => [
        'default_base_url' => '{{base-url}}',
        'default_prefix' => 'api',
        'output_path' => "module/postman_collection_{$randomNumber}.json",
    ],
    // DB diagram settings
    'dbdiagram' => [
        'output_path' => 'module/dbdiagram.dbml',
    ],
];
