<?php

use Api\Controllers;
use Slim\Routing\RouteCollectorProxy;

/**
 * @var \Kipchak\Core\Components\App $app
 */
$app->group('/v1', function (RouteCollectorProxy $group) {
    $group->get('/sultans', [Controllers\v1\Sultans::class, 'list']);
    $group->get('/sultans/{id:[0-9]+}', [Controllers\v1\Sultans::class, 'get']);
});
