<?php

namespace Cms\Core\Workflows\Runtime\Runners\Flow;

use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * N-way branch: evaluates `valueExpr` and leaves by `case:<key>` of the first case whose `match`
 * (a value or a list of values, compared as strings) equals it, else by `default`.
 */
class SwitchRunner implements StepRunner
{
    use NodeHelpers;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        if (!is_string($config['valueExpr'] ?? null) || trim($config['valueExpr']) === '') {
            return StepRunResult::failed('switch: valueExpr is required');
        }

        try {
            $value = $this->evalField($config, 'valueExpr', $context);
        } catch (\Throwable $e) {
            return StepRunResult::failed('switch: ' . $e->getMessage());
        }

        $needle = $this->scalar($value);
        $matched = null;
        foreach ((array) ($config['cases'] ?? []) as $case) {
            $key = is_array($case) ? trim((string) ($case['key'] ?? '')) : '';
            if ($key === '') {
                continue;
            }
            $candidates = $case['match'] ?? $key;
            foreach ((array) $candidates as $candidate) {
                if ($needle !== null && $this->scalar($candidate) === $needle) {
                    $matched = $key;
                    break 2;
                }
            }
        }

        $output = $matched !== null ? 'case:' . $matched : 'default';

        return StepRunResult::success(
            ['value' => $value, 'case' => $matched],
            [['level' => 'info', 'message' => "Switch chose {$output}"]],
        )->withOutput($output);
    }

    protected function scalar(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
