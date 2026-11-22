<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        self::isolateModulesStatusesFile();

        $app = require __DIR__.'/../bootstrap/app.php';

        if (! is_file(dirname(__DIR__) . '/.env')) {
            $app->useEnvironmentPath(__DIR__ . '/Fixtures');
            $app->loadEnvironmentFrom('testing.env');
        }

        $app->make(Kernel::class)->bootstrap();

        // laravel-modules registers module translations through the Module facade the first time the translator is
        // resolved. Resolve it here, before any test mocks that facade: how many modules an install has decides
        // whether boot already did, and a mock without allEnabled() would otherwise answer the first __() call.
        $app->make('translator');

        return $app;
    }

    /**
     * Point the file module activator at a per-process copy of modules_statuses.json.
     *
     * Tests that enable or disable modules persist through the activator; without this
     * they rewrite the tracked file and parallel workers race on it.
     */
    private static function isolateModulesStatusesFile(): void
    {
        static $copy = null;

        if ($copy !== null || getenv('MODULES_STATUSES_FILE') !== false) {
            return;
        }

        $source = dirname(__DIR__) . '/modules_statuses.json';
        $dir = dirname(__DIR__) . '/storage/framework/testing';
        if (! is_file($source) || (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir))) {
            return;
        }

        $copy = $dir . '/modules_statuses.' . getmypid() . '.json';
        copy($source, $copy);
        putenv('MODULES_STATUSES_FILE=' . $copy);
        $_ENV['MODULES_STATUSES_FILE'] = $copy;
        $_SERVER['MODULES_STATUSES_FILE'] = $copy;
        register_shutdown_function(static function () use ($copy): void {
            @unlink($copy);
        });

        // Some runners exit without running shutdown handlers; drop copies of dead processes.
        foreach (glob($dir . '/modules_statuses.*.json') ?: [] as $stale) {
            $pid = (int) preg_replace('/\D/', '', basename($stale, '.json'));
            if ($pid > 0 && $stale !== $copy && function_exists('posix_kill') && ! posix_kill($pid, 0)) {
                @unlink($stale);
            }
        }
    }
}
