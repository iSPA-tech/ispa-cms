<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily LLM usage ledger: requests, failures, fallbacks, refusals, tokens and estimated cost.
 *
 * Mirrors the GeoPlaces Google usage ledger. Every read/write is wrapped so a ledger
 * failure never breaks the AI call itself. Budget month is the UTC `Y-m`.
 */
final class AiUsageLedger
{
    public const VIA_RUNTIME = 'runtime';

    public const VIA_DIRECT = 'direct';

    /**
     * Current UTC budget month key.
     *
     * @return string `Y-m`.
     */
    public function currentMonth(): string
    {
        return now('UTC')->format('Y-m');
    }

    /**
     * Resolve per-1M-token pricing of a model (settings override over config).
     *
     * @param string $model Model name.
     * @return array{input_per_1m_usd: float, cached_input_per_1m_usd: ?float, output_per_1m_usd: ?float}|null Null when the model has no input/output price.
     */
    public function price(string $model): ?array
    {
        // Index the array instead of a dotted config path: model names contain dots (gpt-5.6-luna, grok-4.3).
        $base = (array) (((array) config('core.ai_usage.pricing', []))[$model] ?? []);
        $out = [];
        foreach (['input_per_1m_usd', 'cached_input_per_1m_usd', 'output_per_1m_usd'] as $field) {
            $value = $base[$field] ?? null;
            $stored = $this->setting(AiRuntimeConfigResolver::PRICING_KEY_PREFIX . $model . '.' . $field);
            if ($stored !== '' && is_numeric($stored)) {
                $value = $stored;
            }
            $out[$field] = is_numeric($value) ? (float) $value : null;
        }

        if ($out['input_per_1m_usd'] === null || $out['output_per_1m_usd'] === null) {
            return null;
        }

        return $out;
    }

    /**
     * Estimate the USD cost of one response.
     *
     * @param string $model Model name.
     * @param AiUsageTokens $tokens Normalized tokens.
     * @return float Cost rounded to 6 decimals; 0.0 for an unpriced model.
     */
    public function estimateCostUsd(string $model, AiUsageTokens $tokens): float
    {
        $price = $this->price($model);
        if ($price === null) {
            return 0.0;
        }

        $cachedPrice = $price['cached_input_per_1m_usd'] ?? $price['input_per_1m_usd'];

        return round(
            ($tokens->input * $price['input_per_1m_usd']
                + $tokens->cached * $cachedPrice
                + $tokens->output * $price['output_per_1m_usd']) / 1_000_000,
            6,
        );
    }

    /**
     * Cost of one response: the provider-reported `usage.cost_usd` when present, the price estimate otherwise.
     *
     * @param string $model Model name.
     * @param array<string, mixed> $response Raw provider response.
     * @param AiUsageTokens $tokens Normalized tokens of the response.
     * @return float USD rounded to 6 decimals.
     */
    public function responseCostUsd(string $model, array $response, AiUsageTokens $tokens): float
    {
        $reported = $response['usage']['cost_usd'] ?? null;

        return is_numeric($reported) && (float) $reported >= 0 ? round((float) $reported, 6) : $this->estimateCostUsd($model, $tokens);
    }

    /**
     * Record one provider call in today's ledger row.
     *
     * @param string $provider Provider name.
     * @param string $model Model name.
     * @param string $taskKey Task key.
     * @param array<string, mixed> $response Raw provider response (`[]` on failure).
     * @param bool $failed Whether the call failed.
     * @param bool $fallback Whether this was a fallback attempt.
     * @param int $latencyMs Call latency in milliseconds.
     * @param string $via Row origin on first insert: self::VIA_RUNTIME or self::VIA_DIRECT.
     */
    public function record(string $provider, string $model, string $taskKey, array $response, bool $failed, bool $fallback, int $latencyMs, string $via = self::VIA_RUNTIME): void
    {
        try {
            [$provider, $model, $taskKey] = $this->normalize($provider, $model, $taskKey);
            $tokens = AiUsageTokens::fromResponse($response);
            $cost = $this->responseCostUsd($model, $response, $tokens);
            $this->ensureRow($provider, $model, $taskKey, $via)->incrementEach([
                'request_count' => 1,
                'failed_count' => $failed ? 1 : 0,
                'fallback_count' => $fallback ? 1 : 0,
                'input_tokens' => $tokens->input,
                'cached_input_tokens' => $tokens->cached,
                'output_tokens' => $tokens->output,
                'latency_ms_total' => max(0, $latencyMs),
                'estimated_cost_usd' => $cost,
            ], ['updated_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('AI usage ledger write failed', ['error' => $e::class]);
        }
    }

    /**
     * Record a call refused by the budget guard.
     *
     * @param string $provider Provider name.
     * @param string $model Model name.
     * @param string $taskKey Task key.
     * @param string $via Row origin on first insert: self::VIA_RUNTIME or self::VIA_DIRECT.
     */
    public function recordRefused(string $provider, string $model, string $taskKey, string $via = self::VIA_RUNTIME): void
    {
        try {
            [$provider, $model, $taskKey] = $this->normalize($provider, $model, $taskKey);
            $this->ensureRow($provider, $model, $taskKey, $via)->incrementEach(['refused_count' => 1], ['updated_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('AI usage ledger refusal write failed', ['error' => $e::class]);
        }
    }

    /**
     * Estimated spend of a month.
     *
     * @param string|null $month `Y-m`; defaults to the current UTC month.
     * @return float USD.
     */
    public function monthSpentUsd(?string $month = null): float
    {
        return round((float) DB::table(CoreTableConstant::AI_USAGE_DAILY)
            ->where('month', $month ?? $this->currentMonth())
            ->sum('estimated_cost_usd'), 6);
    }

    /**
     * Usage summary of one month for the admin API.
     *
     * @param string|null $month `Y-m`; invalid or null falls back to the current month.
     * @return array<string, mixed>
     */
    public function summary(?string $month = null): array
    {
        $month = $month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : $this->currentMonth();
        $budget = app(AiRuntimeConfigResolver::class)->budget();
        $cap = $budget['monthly_usd'];

        $sums = 'SUM(request_count) as request_count, SUM(failed_count) as failed_count, SUM(fallback_count) as fallback_count, '
            . 'SUM(refused_count) as refused_count, SUM(input_tokens) as input_tokens, SUM(cached_input_tokens) as cached_input_tokens, '
            . 'SUM(output_tokens) as output_tokens, SUM(latency_ms_total) as latency_ms_total, SUM(estimated_cost_usd) as estimated_cost_usd';

        $byTask = [];
        $byModel = [];
        $rows = collect();
        try {
            $rows = DB::table(CoreTableConstant::AI_USAGE_DAILY)->where('month', $month)
                ->selectRaw("task_key, provider, model, {$sums}")
                ->groupBy('task_key', 'provider', 'model')->get();
        } catch (Throwable $e) {
            Log::warning('AI usage ledger read failed', ['error' => $e::class]);
        }

        $format = static fn (object $r): array => [
            'request_count' => (int) $r->request_count,
            'failed_count' => (int) $r->failed_count,
            'fallback_count' => (int) $r->fallback_count,
            'refused_count' => (int) $r->refused_count,
            'input_tokens' => (int) $r->input_tokens,
            'cached_input_tokens' => (int) $r->cached_input_tokens,
            'output_tokens' => (int) $r->output_tokens,
            'latency_ms_total' => (int) $r->latency_ms_total,
            'estimated_cost_usd' => round((float) $r->estimated_cost_usd, 6),
        ];
        $add = static function (array $acc, array $row): array {
            foreach ($row as $k => $v) {
                $acc[$k] = ($acc[$k] ?? 0) + $v;
            }

            return $acc;
        };

        $totals = [];
        foreach ($rows as $r) {
            $row = $format($r);
            $totals = $add($totals, $row);
            $byTask[$r->task_key] = $add($byTask[$r->task_key] ?? [], $row);
            $key = $r->provider . '|' . $r->model;
            $byModel[$key] = $add($byModel[$key] ?? ['provider' => $r->provider, 'model' => $r->model, 'priced' => $this->price((string) $r->model) !== null], $row);
        }

        $spent = round((float) ($totals['estimated_cost_usd'] ?? 0), 6);

        return [
            'month' => $month,
            'monthly_budget_usd' => $cap,
            'estimated_spent_usd' => $spent,
            'remaining_usd' => $cap > 0 ? round(max(0.0, $cap - $spent), 6) : null,
            'kill_switch' => $budget['kill_switch'],
            'request_count' => (int) ($totals['request_count'] ?? 0),
            'failed_count' => (int) ($totals['failed_count'] ?? 0),
            'fallback_count' => (int) ($totals['fallback_count'] ?? 0),
            'refused_count' => (int) ($totals['refused_count'] ?? 0),
            'input_tokens' => (int) ($totals['input_tokens'] ?? 0),
            'cached_input_tokens' => (int) ($totals['cached_input_tokens'] ?? 0),
            'output_tokens' => (int) ($totals['output_tokens'] ?? 0),
            'by_task' => collect($byTask)->map(static fn (array $v, string $k): array => ['task_key' => $k] + $v)->values()->all(),
            'by_model' => array_values($byModel),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function normalize(string $provider, string $model, string $taskKey): array
    {
        $provider = trim($provider);

        return [
            mb_substr($provider !== '' ? $provider : 'unknown', 0, 40),
            mb_substr(trim($model) !== '' ? trim($model) : 'unknown', 0, 120),
            mb_substr(trim($taskKey) !== '' ? trim($taskKey) : 'unattributed', 0, 150),
        ];
    }

    private function ensureRow(string $provider, string $model, string $taskKey, string $via = self::VIA_RUNTIME): \Illuminate\Database\Query\Builder
    {
        $now = now();
        $date = now('UTC')->toDateString();
        DB::table(CoreTableConstant::AI_USAGE_DAILY)->insertOrIgnore([
            'usage_date' => $date, 'month' => substr($date, 0, 7), 'provider' => $provider, 'model' => $model,
            'task_key' => $taskKey, 'via' => $via, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table(CoreTableConstant::AI_USAGE_DAILY)->where([
            'usage_date' => $date, 'provider' => $provider, 'model' => $model, 'task_key' => $taskKey,
        ]);
    }

    private function setting(string $key): string
    {
        try {
            $value = Setting::query()->where('setting_key', $key)->first()?->value;

            return is_scalar($value) ? trim((string) $value) : '';
        } catch (Throwable) {
            return '';
        }
    }
}
