<?php

use Api\Controllers;
use Api\Receive\Shared;

/**
 * The main app's side of the process-isolation test (Api\Receive\ReceiveApp).
 *
 * @var \Kipchak\Core\Components\App $app
 */
Shared::$mainBooted = true;
if (Shared::$receiveBooted) {
    $app->get('/v1/leaked', [Controllers\Status::class, 'get']);
}
