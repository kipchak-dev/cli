<?php

declare(strict_types=1);

namespace Api\Receive;

use Slim\App;
use Slim\Factory\AppFactory;

/**
 * A second app beside the starter's, as a project runs one in its own worker for uploads: declared in composer.json
 * under extra.kipchak.apps as Api\Receive\ReceiveApp::boot.
 *
 * If it shared a process with the main app, each would see the other's flag in Shared and register a route that
 * it does not register on its own: /receive/v1/leaked here, /v1/leaked in the main app (routes/v1/receive-shared.php).
 */
final class ReceiveApp
{
    public static function boot(): App
    {
        Shared::$receiveBooted = true;
        $app = AppFactory::create();
        $app->put('/receive/v1/files/{name}', [ReceiveController::class, 'put']);
        $app->get('/receive/v1/health', [ReceiveController::class, 'health']);
        if (Shared::$mainBooted) {
            $app->get('/receive/v1/leaked', [ReceiveController::class, 'health']);
        }

        return $app;
    }

    /**
     * The same route as the main app's /status: KIP-009.
     */
    public static function bootWithStatus(): App
    {
        $app = self::boot();
        $app->get('/status', [ReceiveController::class, 'health']);

        return $app;
    }

    /**
     * A route with no operation in the document: KIP-001, naming this app.
     */
    public static function bootWithUnlisted(): App
    {
        $app = self::boot();
        $app->get('/receive/v1/unlisted', [ReceiveController::class, 'health']);

        return $app;
    }
}
