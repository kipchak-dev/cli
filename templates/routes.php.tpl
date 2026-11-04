<?php

use {{controllersNamespace}};
use Slim\Routing\RouteCollectorProxy;

/**
 * @var \Kipchak\Core\Components\App $app
 */
$app->group('/{{version}}', function (RouteCollectorProxy $group) {
    $group->get('/{{segment}}', [Controllers\{{version}}\{{controller}}::class, 'list']);
    $group->get('/{{segment}}/{id:[0-9]+}', [Controllers\{{version}}\{{controller}}::class, 'get']);
{{crudRoutes}}});
