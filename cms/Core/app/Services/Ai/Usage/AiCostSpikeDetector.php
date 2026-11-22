<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai\Usage;

use Carbon\CarbonInterface;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily AI cost spike check over the usage ledger.
 *
 * Compares today's UTC cost so far with the average daily cost of the previous
 * full days (zero-cost days included) and raises one CMSLogger warning per UTC
 * day when today reaches max(min_usd, multiplier x average). The absolute floor
 * keeps low-spend sites quiet; the monthly cap alert in AiBudgetGuard catches
 * slow overspend, this catches a runaway loop within the day.
 */
final class AiCostSpikeDetector
{
    public function __construct(private readonly AiRuntimeConfigResolver $resolver)
    {
    }

    /**
     * Evaluate today's spend against the baseline without alerting.
     *
     * @param CarbonInterface|null $now Clock override for tests; defaults to now().
     * @return array{date: string, enabled: bool, today_usd: float, baseline_usd: float, threshold_usd: float, spike: bool, top_tasks: list<array{task_key: string, cost_usd: float}>}
     */
    public function evaluate(?CarbonInterface $now = null): array
    {
        $settings = $this->resolver->spike();
        $now = ($now ?? now())->copy()->utc();
        $today = $now->toDateString();
        $days = $settings['baseline_days'];
        $from = $now->copy()->subDays($days)->toDateString();

        $table = DB::table(CoreTableConstant::AI_USAGE_DAILY);
        $todayUsd = round((float) (clone $table)->where('usage_date', $today)->sum('estimated_cost_usd'), 6);
        $baselineTotal = (float) (clone $table)->where('usage_date', '>=', $from)->where('usage_date', '<', $today)->sum('estimated_cost_usd');
        $baselineUsd = round($baselineTotal / $days, 6);
        $thresholdUsd = round(max($settings['min_usd'], $settings['multiplier'] * $baselineUsd), 6);

        $topTasks = (clone $table)->where('usage_date', $today)
            ->select('task_key')
            ->selectRaw('SUM(estimated_cost_usd) as cost_usd')
            ->groupBy('task_key')
            ->orderByDesc('cost_usd')
            ->limit(3)
            ->get()
            ->map(static fn (object $row): array => ['task_key' => (string) $row->task_key, 'cost_usd' => round((float) $row->cost_usd, 6)])
            ->all();

        return [
            'date' => $today,
            'enabled' => $settings['enabled'],
            'today_usd' => $todayUsd,
            'baseline_usd' => $baselineUsd,
            'threshold_usd' => $thresholdUsd,
            'spike' => $settings['enabled'] && $todayUsd > 0.0 && $todayUsd >= $thresholdUsd,
            'top_tasks' => $topTasks,
        ];
    }

    /**
     * Evaluate and send the once-per-UTC-day alert when spending spikes.
     *
     * Never throws: a failing check is logged and reported as no spike, so the
     * scheduler keeps running.
     *
     * @param CarbonInterface|null $now Clock override for tests; defaults to now().
     * @return array<string, mixed> The evaluation plus `alerted` (bool).
     */
    public function alertIfSpiking(?CarbonInterface $now = null): array
    {
        try {
            $result = $this->evaluate($now);
        } catch (Throwable $e) {
            Log::warning('AI cost spike check failed', ['error' => $e::class]);

            return ['spike' => false, 'alerted' => false];
        }

        if (! $result['spike'] || ! Cache::add('core:ai_cost_spike:' . $result['date'], true, now()->addDays(2))) {
            return $result + ['alerted' => false];
        }

        $top = implode(', ', array_map(
            static fn (array $task): string => sprintf('%s %.2f USD', $task['task_key'], $task['cost_usd']),
            $result['top_tasks'],
        ));
        $site = parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.name');

        CMSLogger::warning(['message' => sprintf(
            'AI cost spike on %s (%s UTC): %.2f USD so far today vs %.2f USD/day average (threshold %.2f). Top tasks: %s',
            $site,
            $result['date'],
            $result['today_usd'],
            $result['baseline_usd'],
            $result['threshold_usd'],
            $top !== '' ? $top : 'n/a',
        )]);

        return $result + ['alerted' => true];
    }
}
