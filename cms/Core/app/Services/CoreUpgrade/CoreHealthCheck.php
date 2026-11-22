<?php

namespace Cms\Core\Services\CoreUpgrade;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Throwable;

/**
 * Post-swap health check: the home page and the admin API both must answer without a 5xx (a 401/404 is fine).
 *
 * The requests go through the HTTP kernel of the running process, so this is a best-effort check of the freshly placed
 * files (views, routes, config and lazily loaded classes are read again), not of a cold start. Tests replace this class.
 */
class CoreHealthCheck
{
    /** Paths probed; the second is an authenticated admin API route, so 401 is the healthy answer. */
    public const PATHS = ['/', '/api/v2/admin/agentic/health'];

    /**
     * @return list<string> One line per failing probe; empty when healthy.
     */
    public function check(): array
    {
        $failures = [];
        foreach (self::PATHS as $path) {
            try {
                $kernel = app(Kernel::class);
                $response = $kernel->handle(Request::create($path, 'GET'));
                $status = $response->getStatusCode();
                $kernel->terminate(Request::create($path, 'GET'), $response);
                if ($status >= 500) {
                    $failures[] = "GET {$path} answered HTTP {$status}";
                }
            } catch (Throwable $e) {
                $failures[] = "GET {$path} threw ".class_basename($e);
            }
        }

        return $failures;
    }
}
