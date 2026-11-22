<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every HTTP request one request id, kept in the Laravel Context so API logs, AI call logs
 * and the queued jobs dispatched by the request (Context is carried into job payloads) share it.
 *
 * An incoming `X-Request-Id` is reused only when it is a safe token (max 64 chars of `[A-Za-z0-9._-]`).
 * Also marks the Context origin as `http` (hidden) so AiCallLog can tell web requests from console runs.
 */
final class AssignRequestId
{
    /**
     * @param Request $request Incoming request.
     * @param Closure(Request): Response $next Next middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id');
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        Context::add('request_id', $requestId);
        Context::addHidden('origin', 'http');

        $response = $next($request);
        // ApiLogHandler may have replaced the id (uuid-column fallback); report the final one.
        $response->headers->set('X-Request-Id', (string) Context::get('request_id', $requestId));

        return $response;
    }
}
