<?php

use function Kipchak\Core\env;

/**
 * SQLite is the starting default, so that the first migration and query work without a database server. Use MySQL or
 * PostgreSQL in production: set the connection below as https://kipchak.dev/docs/driver-doctrine#configuration shows.
 */
return [
    'dbal' => [
        'enabled' => true,
        'connections' => [
            'primary' => [
                'driver' => 'pdo_sqlite',
                'path' => env('SQLITE_PATH', dirname(__DIR__) . '/var/app.sqlite'),
            ],
        ],
    ],

    'orm' => [
        'enabled' => true,
        'entity_managers' => [
            'primary' => [
                'dev_mode' => (bool) env('DEBUG', false),
                'metadata_dirs' => [
                    dirname(__DIR__) . '/api/Entities/Doctrine/Primary',
                ],
                'metadata_format' => 'attributes',
                'connection' => 'primary',
                'cache' => [
                    'enabled' => true,
                    'store' => 'file', // To use Memcached: kipchak add memcached, then 'memcached' here.
                ],
                'cache_config' => [
                    'file' => [
                        'dir' => '/tmp',
                    ],
                    'memcached' => [
                        'pool' => 'cache',
                    ],
                ],
                // Recommended in worker mode: call reopenIfClosed() on the entity manager at the top of each request.
                'reopenable' => true,
            ],
        ],
    ],
];
