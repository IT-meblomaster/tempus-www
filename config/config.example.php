<?php

return [
    'app' => [
        'name' => 'Template',
        'base_url' => '',
    ],

    'debug' => true,
//    'log_errors' => true,
//    'error_log' => __DIR__ . '/../var/logs/php-error.log',

    'db' => [
        'host' => 'DB_ADRESS',
        'port' => DB_PORT,
        'name' => 'DATABASE_NAME',
        'user' => 'DATABASE_USERNAME',
        'pass' => 'DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'template_www_sess',
        'csrf_key' => 'change_this_random_string',
    ],
];
