<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiTaskCatalog;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-call LLM trace log (`core_ai_call_logs`): one metadata row per provider attempt or refusal.
 *
 * Never stores prompt or response content, only a sha256 of the canonical prompt parts so repeated
 * identical prompts can be spotted; tasks declared private (AiTaskCatalog::isPromptPrivate) keep no hash. Every write is fail-open: an error is logged as a warning and
 * never reaches the AI call. Successful calls are sampled (`core.ai_runtime.trace.sample_percent`);
 * failures and refusals are always logged.
 */
final class AiCallLog
{
    /** Payload keys that make up the prompt (model and sampling parameters are excluded). */
    private const PROMPT_KEYS = ['messages', 'system', 'instructions', 'input', 'tools'];

    /** Number of queue jobs currently being processed in this process. */
    private static int $jobDepth = 0;

    public function __construct(
        private readonly AiUsageLedger $ledger,
        private readonly AiRuntimeConfigResolver $resolver,
        private readonly ?AiTaskCatalog $catalog = null,
    ) {
    }

    /**
     * Track queue job execution so call origin can be told apart (listeners survive for the process lifetime).
     *
     * @param Dispatcher $events Event dispatcher.
     */
    public static function trackQueueJobs(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, static function (): void {
            self::$jobDepth++;
        });
        $done = static function (): void {
            self::$jobDepth = max(0, self::$jobDepth - 1);
        };
        $events->listen(JobProcessed::class, $done);
        $events->listen(JobExceptionOccurred::class, $done);
    }

    /**
     * Where the current call runs: `queue` inside a queue job (JobProcessing..JobProcessed, which also
     * covers the sync driver), `http` when AssignRequestId marked this request's Context, else `console`.
     */
    public function origin(): string
    {
        if (self::$jobDepth > 0) {
            return 'queue';
        }

        return Context::getHidden('origin') === 'http' ? 'http' : 'console';
    }

    /**
     * Log one provider attempt.
     *
     * @param string $provider Provider name.
     * @param string $model Model name.
     * @param string $taskKey Task key.
     * @param string $via AiUsageLedger::VIA_RUNTIME or VIA_DIRECT.
     * @param int $attempt 1-based attempt number within the gateway call.
     * @param bool $fallback Whether this was a fallback attempt.
     * @param bool $failed Whether the attempt failed.
     * @param int $latencyMs Latency in milliseconds.
     * @param array<string, mixed> $response Raw provider response (`[]` on failure).
     * @param array<string, mixed>|null $payload Request payload used only to derive the prompt hash.
     */
    public function record(string $provider, string $model, string $taskKey, string $via, int $attempt, bool $fallback, bool $failed, int $latencyMs, array $response, ?array $payload = null): void
    {
        try {
            if (! $failed && ! $this->sampled()) {
                return;
            }
            $tokens = AiUsageTokens::fromResponse($response);
            $this->insert($provider, $model, $taskKey, $via, $attempt, $fallback, $failed, null, $latencyMs, $tokens, $this->ledger->responseCostUsd($model, $response, $tokens), $payload);
        } catch (Throwable $e) {
            Log::warning('AI call log write failed', ['error' => $e::class]);
        }
    }

    /**
     * Log a call refused by the budget guard (always logged).
     *
     * @param string $provider Provider name.
     * @param string $model Model name.
     * @param string $taskKey Task key.
     * @param string $via AiUsageLedger::VIA_RUNTIME or VIA_DIRECT.
     * @param int $attempt Attempt number that was refused.
     * @param bool $fallback Whether the refused attempt was a fallback.
     * @param string $reason Guard reason (kill_switch, budget_exceeded).
     */
    public function recordRefused(string $provider, string $model, string $taskKey, string $via, int $attempt, bool $fallback, string $reason): void
    {
        try {
            $this->insert($provider, $model, $taskKey, $via, $attempt, $fallback, false, $reason, 0, new AiUsageTokens(), 0.0, null);
        } catch (Throwable $e) {
            Log::warning('AI call log refusal write failed', ['error' => $e::class]);
        }
    }

    /**
     * Recent calls of a task and its duplicate-prompt rate over the last 7 days.
     *
     * @param string $task Task key.
     * @param int $limit Rows to return, clamped to 1..100.
     * @return array<string, mixed>
     */
    public function forTask(string $task, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = DB::table(CoreTableConstant::AI_CALL_LOGS)->where('task_key', $task)
            ->orderByDesc('called_at')->orderByDesc('id')->limit($limit)->get();

        // Only first attempts that were actually sent: a fallback attempt repeats its own request's
        // prompt and a refused call sent nothing, so counting them would inflate the duplicate rate.
        $since = now('UTC')->subDays(7)->format('Y-m-d H:i:s');
        $hashed = static fn () => DB::table(CoreTableConstant::AI_CALL_LOGS)
            ->where('task_key', $task)->where('called_at', '>=', $since)->whereNotNull('prompt_hash')
            ->where('attempt', 1)->whereNull('refused_reason');
        $withHash = (int) $hashed()->count();
        $repeated = $hashed()->groupBy('prompt_hash')->havingRaw('COUNT(*) > 1')->selectRaw('COUNT(*) as c')->get();
        $duplicates = (int) $repeated->sum('c');

        return [
            'task' => $task,
            'calls' => $rows->map(static fn (object $r): array => [
                'called_at' => Carbon::parse((string) $r->called_at, 'UTC')->toIso8601String(),
                'provider' => $r->provider,
                'model' => $r->model,
                'via' => $r->via,
                'attempt' => (int) $r->attempt,
                'is_fallback' => (bool) $r->is_fallback,
                'failed' => (bool) $r->failed,
                'refused_reason' => $r->refused_reason,
                'latency_ms' => (int) $r->latency_ms,
                'input_tokens' => (int) $r->input_tokens,
                'cached_input_tokens' => (int) $r->cached_input_tokens,
                'output_tokens' => (int) $r->output_tokens,
                'cost_usd' => round((float) $r->cost_usd, 6),
                'request_id' => $r->request_id,
                'origin' => $r->origin,
            ])->all(),
            'duplicate_prompt_7d' => [
                'calls_with_hash' => $withHash,
                'duplicate_calls' => $duplicates,
                'rate' => $withHash > 0 ? round($duplicates / $withHash, 4) : 0,
            ],
        ];
    }

    /**
     * Delete rows older than a number of days.
     *
     * @param int $days Retention in days.
     * @return int Deleted rows.
     */
    public function prune(int $days): int
    {
        return DB::table(CoreTableConstant::AI_CALL_LOGS)
            ->where('called_at', '<', now('UTC')->subDays(max(0, $days))->format('Y-m-d H:i:s'))->delete();
    }

    /**
     * Canonical prompt hash: sha256 of the key-sorted JSON of the prompt parts only.
     *
     * @param array<string, mixed> $payload Request payload.
     * @return string|null Null when the payload has no prompt parts.
     */
    public static function promptHash(array $payload): ?string
    {
        $parts = array_intersect_key($payload, array_flip(self::PROMPT_KEYS));
        if ($parts === []) {
            return null;
        }

        return hash('sha256', (string) json_encode(self::canonical($parts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function sampled(): bool
    {
        $percent = $this->resolver->traceSamplePercent();

        return $percent >= 100 || ($percent > 0 && random_int(1, 100) <= $percent);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function insert(string $provider, string $model, string $taskKey, string $via, int $attempt, bool $fallback, bool $failed, ?string $refusedReason, int $latencyMs, AiUsageTokens $tokens, float $cost, ?array $payload): void
    {
        $requestId = Context::get('request_id');
        DB::table(CoreTableConstant::AI_CALL_LOGS)->insert([
            'called_at' => now('UTC')->format('Y-m-d H:i:s'),
            'task_key' => mb_substr(trim($taskKey) !== '' ? trim($taskKey) : 'unattributed', 0, 150),
            'provider' => mb_substr(trim($provider) !== '' ? trim($provider) : 'unknown', 0, 40),
            'model' => mb_substr(trim($model) !== '' ? trim($model) : 'unknown', 0, 120),
            'via' => mb_substr($via, 0, 20),
            'attempt' => max(1, min(65535, $attempt)),
            'is_fallback' => $fallback,
            'failed' => $failed,
            'refused_reason' => $refusedReason !== null ? mb_substr($refusedReason, 0, 40) : null,
            'latency_ms' => max(0, $latencyMs),
            'input_tokens' => $tokens->input,
            'cached_input_tokens' => $tokens->cached,
            'output_tokens' => $tokens->output,
            'cost_usd' => $cost,
            // A task with a private prompt (AiTaskCatalog::isPromptPrivate) keeps no prompt-derived value.
            'prompt_hash' => $payload !== null && ! ($this->catalog ?? new AiTaskCatalog())->isPromptPrivate($taskKey)
                ? self::promptHash($payload)
                : null,
            'request_id' => is_string($requestId) && $requestId !== '' ? mb_substr($requestId, 0, 64) : null,
            'origin' => $this->origin(),
        ]);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
