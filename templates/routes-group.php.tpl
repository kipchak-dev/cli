<?php

use {{controllersNamespace}};
use Slim\Routing\RouteCollectorProxy;

/**
 * @var \Kipchak\Core\Components\App $app
 */
$app->group('/{{version}}', function (RouteCollectorProxy $group) {
{{routes}}});
