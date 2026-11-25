<?php

namespace Cms\Core\Workflows\Runtime\Runners\Flow;

use Carbon\CarbonImmutable;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Pauses the run, then continues from the next step in a new queued execution (same rescheduling as `delay`).
 *
 * Modes: `duration` (`amount` + `unit` seconds|minutes|hours|days) and `until` (`untilExpr`, a date/time;
 * a time already past continues at once). Waits are capped by `core.workflow.max_wait_seconds` (default 31 days).
 */
class WaitRunner implements StepRunner
{
    use NodeHelpers;

    protected const UNITS = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400];

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $mode = (string) ($config['mode'] ?? 'duration');

        if ($mode === 'duration') {
            $unit = (string) ($config['unit'] ?? 'seconds');
            if (!isset(self::UNITS[$unit]) || !is_numeric($config['amount'] ?? null) || $config['amount'] < 0) {
                return StepRunResult::failed('wait: amount must be a number >= 0 and unit seconds|minutes|hours|days');
            }
            $seconds = (int) round((float) $config['amount'] * self::UNITS[$unit]);
        } elseif ($mode === 'until') {
            try {
                $until = $this->evalField($config, 'untilExpr', $context);
                $target = $until instanceof \DateTimeInterface
                    ? CarbonImmutable::instance($until)
                    : CarbonImmutable::parse((string) $until, $config['timezone'] ?? null);
            } catch (\Throwable $e) {
                return StepRunResult::failed('wait: untilExpr is not a date/time');
            }
            $seconds = max(0, CarbonImmutable::now()->diffInSeconds($target, false));
            $seconds = (int) ceil($seconds);
        } else {
            return StepRunResult::failed('wait: mode must be duration or until');
        }

        $cap = (int) config('core.workflow.max_wait_seconds', 31 * 86400);
        if ($seconds > $cap) {
            return StepRunResult::failed("wait: {$seconds}s exceeds the limit of {$cap}s");
        }

        $logs = [['level' => 'info', 'message' => "Wait {$seconds}s ({$mode})"]];
        if ($seconds <= 0) {
            return StepRunResult::success(['waited' => 0], $logs);
        }

        return StepRunResult::rescheduled($seconds, ['waited' => $seconds, 'rescheduled' => true], $logs);
    }
}
