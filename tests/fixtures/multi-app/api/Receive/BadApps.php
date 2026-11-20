<?php

declare(strict_types=1);

namespace Api\Receive;

use RuntimeException;
use Slim\App;

/**
 * Declarations that cannot give an app's routes, each of which openapi:validate reports as KIP-008.
 */
final class BadApps
{
    public function instance(): App
    {
        return ReceiveApp::boot();
    }

    public static function withArgument(string $name): App
    {
        return ReceiveApp::boot();
    }

    public static function throws(): App
    {
        throw new RuntimeException('The receive app is not configured.');
    }

    public static function notAnApp(): object
    {
        return new \stdClass();
    }

    /**
     * Exhausts the memory limit: a fatal error, which no catch block sees.
     */
    public static function fatal(): App
    {
        ini_set('memory_limit', '16M');
        $blocks = [];
        while (true) {
            $blocks[] = str_repeat('x', 1024 * 1024);
        }
    }

    public static function exits(): App
    {
        exit(0);
    }

    /**
     * Never returns. Writes its process ID to hang.pid in the project, so that the test can check that the process
     * was ended.
     */
    public static function hangs(): App
    {
        file_put_contents('hang.pid', (string) getmypid());
        while (true) {
            sleep(1);
        }
    }

    private static function hidden(): App
    {
        return ReceiveApp::boot();
    }
}
