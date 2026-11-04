<?php

use Kipchak\Middleware\Error\Error;
use Slim\App;

/**
 * Middleware initialised here applies to every request. Slim runs the middleware added last first.
 *
 * Error is initialised twice. The first is the innermost middleware: it turns an exception from a route into a JSON
 * error response, so that middleware which observes responses (OpenTelemetry, Metering, Redaction, Idempotency)
 * sees that response. The last is the outermost: it turns an exception thrown by any other middleware into the same
 * JSON response, instead of a bare 500. Add other middleware between the two. tests/MiddlewareOrderTest.php fails if
 * Error is not last.
 *
 * The middleware available for Kipchak is listed on https://kipchak.dev/docs/middleware.
 *
 * @var App $app
 */

Error::initialise($app);

// Other middleware goes here.

Error::initialise($app);
