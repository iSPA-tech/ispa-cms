<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Exceptions\WorkflowAlreadyRunning;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Inbound webhooks for `trigger_webhook` projects.
 *
 * Order of checks (nothing else is done for a request that fails one): body size, token → active project whose
 * start node is `trigger_webhook`, secret (constant-time), per-project rate limit. Auth modes from the node's
 * `auth`: `hmac` (default) = headers `X-Workflow-Timestamp: <unix seconds>` and
 * `X-Workflow-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">`, refused outside a 5-minute window
 * and refused when the same signature was already accepted (replay); `token` = header `X-Workflow-Token: <secret>`.
 * The secret is never logged or echoed.
 *
 * The body, query and a few headers go to the run as untrusted data (`trigger.meta.webhook`, `tainted: true`).
 * `respond` = `immediate` answers 202 at once; `wait` holds the request up to `webhook_wait_seconds` for a
 * `webhook_respond` node's answer.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class WebhookTriggerService
{
    public const SIGNATURE_HEADER = 'X-Workflow-Signature';
    public const TIMESTAMP_HEADER = 'X-Workflow-Timestamp';
    public const TOKEN_HEADER = 'X-Workflow-Token';
    /** Accepted clock skew for signed calls, seconds (both directions). */
    public const TOLERANCE_SECONDS = 300;
    public const RESPONSE_VAR = '__webhook_response';

    /** Request headers passed to the run (never auth headers). */
    protected const PASSED_HEADERS = ['content-type', 'user-agent', 'x-request-id', 'x-github-event', 'x-event-type'];

    public function __construct(protected WorkflowProjectRunService $runService)
    {
    }

    /**
     * Issue a new token and secret (the old ones stop working at once). Returns the secret: show it once.
     *
     * @return array{token: string, secret: string}
     */
    public function rotate(WorkflowProject $project): array
    {
        $token = Str::random(48);
        $secret = Str::random(48);
        $project->forceFill(['webhook_token' => $token, 'webhook_secret' => $secret])->save();

        return ['token' => $token, 'secret' => $secret];
    }

    public function disable(WorkflowProject $project): void
    {
        $project->forceFill(['webhook_token' => null, 'webhook_secret' => null])->save();
    }

    /**
     * Handle one inbound call.
     *
     * @return array{status: int, body: mixed, content_type?: string}
     */
    public function handle(Request $request, string $token): array
    {
        // A read-only demo site starts no run from outside: the route is anonymous, so the demo guard never sees it
        // (review finding 35). Refused before any lookup.
        if ((bool) config('demo.enabled', false)) {
            return ['status' => 403, 'body' => ['message' => 'Webhooks are turned off on this demo site.', 'reason' => 'demo_read_only']];
        }

        $max = max(1, (int) WorkflowTriggers::setting('webhook_max_bytes', 262144));
        if ((int) $request->header('Content-Length', '0') > $max) {
            return ['status' => 413, 'body' => ['message' => 'Payload too large.']];
        }

        $raw = (string) $request->getContent();
        if (strlen($raw) > $max) {
            return ['status' => 413, 'body' => ['message' => 'Payload too large.']];
        }

        $notFound = ['status' => 404, 'body' => ['message' => 'Not found.']];
        if (strlen($token) < 32 || strlen($token) > 64) {
            return $notFound;
        }

        $project = WorkflowProject::query()->where('webhook_token', $token)->where('is_active', true)->first();
        // The published version's start node and settings (auth mode, respond), never the draft's.
        $start = $project ? WorkflowTriggers::publishedStartStep($project) : null;
        if (! $project || $start?->step_type !== WorkflowTriggers::WEBHOOK) {
            return $notFound;
        }

        $config = is_array($start->step_config) ? $start->step_config : [];
        try {
            $secret = (string) $project->webhook_secret;
        } catch (\Throwable) {
            // Unreadable secret (e.g. the app key changed): treated as no secret, never as a match.
            $secret = '';
        }
        if (! $this->verify($request, $raw, $secret, (string) ($config['auth'] ?? 'hmac'), (int) $project->id)) {
            return ['status' => 401, 'body' => ['message' => 'Unauthorized.']];
        }

        $limiterKey = 'workflow-webhook:' . $project->id;
        if (! RateLimiter::attempt($limiterKey, max(1, (int) WorkflowTriggers::setting('webhook_per_minute', 60)), static fn () => true, 60)) {
            return ['status' => 429, 'body' => ['message' => 'Too many requests.']];
        }

        $body = $raw;
        if ($raw !== '' && str_contains(strtolower((string) $request->header('Content-Type')), 'json')) {
            $decoded = json_decode($raw, true, 32);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['status' => 400, 'body' => ['message' => 'Invalid JSON body.']];
            }
            $body = $decoded;
        }

        $headers = [];
        foreach (self::PASSED_HEADERS as $name) {
            if ($request->headers->has($name)) {
                $headers[$name] = Str::limit((string) $request->header($name), 500, '');
            }
        }

        try {
            $instance = $this->runService->startTriggered($project, 'webhook', [
                'webhook' => [
                    'body' => $body,
                    'query' => array_slice($request->query(), 0, 50, true),
                    'headers' => $headers,
                    'method' => $request->method(),
                    'tainted' => true,
                ],
            ], []);
        } catch (WorkflowTriggerRefused $e) {
            return ['status' => 409, 'body' => ['message' => 'Not started.', 'reason' => $e->reason]];
        } catch (WorkflowAlreadyRunning $e) {
            return ['status' => 409, 'body' => ['message' => 'Not started.', 'reason' => $e::CODE]];
        } catch (\Cms\Core\Services\Workflow\Exceptions\WorkflowProjectInactive $e) {
            // Turned off, globally paused, breaker tripped, not published or failing its integrity check.
            return ['status' => 409, 'body' => ['message' => 'Not started.', 'reason' => $e::CODE]];
        } catch (\Throwable) {
            return ['status' => 409, 'body' => ['message' => 'Not started.', 'reason' => 'start_failed']];
        }

        if (($config['respond'] ?? 'immediate') !== 'wait') {
            return ['status' => 202, 'body' => ['accepted' => true]];
        }

        return $this->waitForResponse($instance);
    }

    /**
     * `hmac` mode also records the accepted signature until the window ends, so the same call cannot be replayed.
     */
    public function verify(Request $request, string $raw, string $secret, string $mode, int $projectId = 0): bool
    {
        if ($secret === '') {
            return false;
        }

        if ($mode === 'token') {
            return hash_equals($secret, (string) $request->header(self::TOKEN_HEADER, ''));
        }

        $timestamp = trim((string) $request->header(self::TIMESTAMP_HEADER, ''));
        if (! ctype_digit($timestamp) || strlen($timestamp) > 12 || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $given = (string) $request->header(self::SIGNATURE_HEADER, '');
        $given = strtolower(str_starts_with($given, 'sha256=') ? substr($given, 7) : $given);
        if (! hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, $secret), $given)) {
            return false;
        }

        // Nonce: the first call with this signature wins; the key outlives the window on both sides.
        return TriggerIndex::cache()->add('workflow-webhook-sig:' . $projectId . ':' . $given, 1, self::TOLERANCE_SECONDS * 2 + 60);
    }

    /**
     * @return array{status: int, body: mixed, content_type?: string}
     */
    protected function waitForResponse(WorkflowInstance $instance): array
    {
        $deadline = microtime(true) + max(0, min(30, (int) WorkflowTriggers::setting('webhook_wait_seconds', 10)));

        do {
            $instance->refresh();
            $response = data_get($instance->context_snapshot, 'vars.' . self::RESPONSE_VAR);
            if (is_array($response)) {
                $status = (int) ($response['status'] ?? 200);

                return [
                    'status' => $status >= 200 && $status <= 599 ? $status : 200,
                    'body' => $response['body'] ?? null,
                ];
            }

            if ($instance->finished_at !== null) {
                return ['status' => $instance->status === 'completed' ? 200 : 500, 'body' => ['status' => $instance->status]];
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        return ['status' => 202, 'body' => ['accepted' => true]];
    }
}
