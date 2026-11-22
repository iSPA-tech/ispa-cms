<?php

declare(strict_types=1);

namespace Cms\Core\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outermost middleware of POST /api/v2/public/agent/answers.
 *
 * Fixes one request UUID before auth, throttle and controller work, and gives every response the exact envelope
 * `{data: {request_id, status, answer, conflict, as_of, citations, error}}`. Responses the controller already built
 * pass through; any other response (401 from auth, 429 from throttle, 422 from validation, an exception page) is
 * replaced by the fixed error for its status, so no framework or exception text leaks. Always private, never stored.
 */
final class AgentAnswerEnvelope
{
    public const REQUEST_ID = 'agent_answer.request_id';
    private const BUILT = 'agent_answer.enveloped';

    /** @var array<int, array{0: string, 1: string}> */
    private const ERRORS = [
        401 => ['authentication_required', 'Authentication required.'],
        403 => ['agent_answer_forbidden', 'You are not allowed to use agent answers.'],
        422 => ['invalid_request', 'The request is invalid.'],
        429 => ['rate_limited', 'Too many agent answer requests.'],
        502 => ['agent_response_invalid', 'The agent returned an invalid evidence response.'],
        503 => ['agent_unavailable', 'Agent answers are unavailable.'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Share the global request id (AssignRequestId, kept in Context and echoed in X-Request-Id); a client-sent
        // id that is not a UUID is replaced so the body always carries a UUID.
        $requestId = (string) Context::get('request_id', '');
        if (! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid();
            Context::add('request_id', $requestId);
        }
        $request->attributes->set(self::REQUEST_ID, $requestId);

        $response = $next($request);

        if (! $request->attributes->get(self::BUILT, false)) {
            $status = $response->getStatusCode();
            $status = isset(self::ERRORS[$status]) ? $status : 503;
            $replacement = self::error($request, $status);
            foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $header) {
                if ($response->headers->has($header)) {
                    $replacement->headers->set($header, (string) $response->headers->get($header));
                }
            }
            $response = $replacement;
        }

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Success body for `answered` and `insufficient_evidence`.
     *
     * @param list<array<string, mixed>> $citations
     */
    public static function success(Request $request, string $status, string $answer, bool $conflict, ?string $asOf, array $citations): JsonResponse
    {
        return self::respond($request, 200, [
            'status' => $status,
            'answer' => $answer,
            'conflict' => $conflict,
            'as_of' => $asOf,
            'citations' => $citations,
            'error' => null,
        ]);
    }

    /** Fixed error body for one of the frozen statuses (401, 403, 422, 429, 502, 503). */
    public static function error(Request $request, int $status): JsonResponse
    {
        [$code, $message] = self::ERRORS[$status] ?? self::ERRORS[503];

        return self::respond($request, isset(self::ERRORS[$status]) ? $status : 503, [
            'status' => 'error',
            'answer' => null,
            'conflict' => false,
            'as_of' => null,
            'citations' => [],
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    /** @param array<string, mixed> $fields */
    private static function respond(Request $request, int $status, array $fields): JsonResponse
    {
        $request->attributes->set(self::BUILT, true);

        return new JsonResponse(
            ['data' => ['request_id' => (string) $request->attributes->get(self::REQUEST_ID, '')] + $fields],
            $status,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
