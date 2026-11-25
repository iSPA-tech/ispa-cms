<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Carbon\CarbonImmutable;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\Cache;

/**
 * At most `max` passes per `per_seconds` window for `keyExpr`, shared by all runs of the project (cache counters,
 * atomic add + increment per fixed window).
 *
 * Over the limit: `on_limit=skip` leaves by `limited`; `on_limit=wait` reserves a slot in the next window that has
 * room (up to 10 windows / the wait cap ahead) and continues by `ok` after the delay (rescheduled run).
 */
class RateLimitRunner implements StepRunner
{
    use NodeHelpers;

    public const HANDLES = ['ok', 'limited'];
    protected const LOOKAHEAD_WINDOWS = 10;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $max = (int) ($config['max'] ?? 0);
        $per = (int) ($config['per_seconds'] ?? 0);
        $onLimit = (string) ($config['on_limit'] ?? 'skip');

        if ($max < 1 || $per < 1 || $per > 86400) {
            return StepRunResult::failed('rate_limit: max >= 1 and per_seconds 1..86400 are required');
        }
        if (!in_array($onLimit, ['skip', 'wait'], true)) {
            return StepRunResult::failed('rate_limit: on_limit must be skip or wait');
        }

        try {
            $key = $this->evalField($config, 'keyExpr', $context, 'default');
        } catch (\Throwable $e) {
            return StepRunResult::failed('rate_limit: ' . $e->getMessage());
        }
        if (!is_scalar($key) || (string) $key === '') {
            return StepRunResult::failed('rate_limit: keyExpr must resolve to a non-empty value');
        }

        $base = 'wf-rate:' . $this->projectKey($step) . ':' . hash('sha256', (string) $key) . ':' . $per . ':';
        $now = CarbonImmutable::now()->getTimestamp();
        $window = intdiv($now, $per);
        $maxWait = (int) config('core.workflow.max_wait_seconds', 31 * 86400);
        $lookahead = $onLimit === 'wait' ? self::LOOKAHEAD_WINDOWS : 0;

        for ($i = 0; $i <= $lookahead; $i++) {
            $w = $window + $i;
            $delay = $i === 0 ? 0 : $w * $per - $now;
            if ($delay > $maxWait) {
                break;
            }
            if ($this->take($base . $w, $max, ($i + 2) * $per)) {
                $data = ['limited' => false, 'waited' => $delay];
                $result = $delay > 0
                    ? StepRunResult::rescheduled($delay, $data, [['level' => 'info', 'message' => "Rate limit: slot in {$delay}s"]])
                    : StepRunResult::success($data);

                return $this->routeOutput($result, $step, 'ok', self::HANDLES, false);
            }
        }

        if ($onLimit === 'wait') {
            return StepRunResult::failed('rate_limit: no free slot within the wait window');
        }

        return $this->routeOutput(StepRunResult::success(['limited' => true]), $step, 'limited', self::HANDLES, true);
    }

    protected function take(string $key, int $max, int $ttl): bool
    {
        Cache::add($key, 0, $ttl);
        $count = (int) Cache::increment($key);

        return $count <= $max;
    }
}
