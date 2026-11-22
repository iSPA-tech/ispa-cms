<?php

declare(strict_types=1);

namespace Cms\Skeleton\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The public pages answer 404 while the module is turned off (modules_statuses.json). */
final class EnsureSkeletonEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $modules = app()->bound('modules') ? app('modules') : null;
        abort_if($modules === null || ! $modules->isEnabled('Skeleton'), 404);

        return $next($request);
    }
}
