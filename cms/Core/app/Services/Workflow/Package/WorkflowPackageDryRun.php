<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Package;

use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Upgrade\StepConfigUpgrader;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Dry run of a workflow package: walks the graph from its start step with `sample.root` as the record, without side
 * effects and without spending.
 *
 * Only pure in-memory steps run for real ({@see self::EXECUTED}: variables, branches, templates, text/JSON/date
 * transforms, assertions), so the branch taken and the values built are real. Every other step is stubbed and only
 * reported ("would ..." with its safety classes): writes, outside calls, AI calls, publishes, reads of site data,
 * approvals (the run continues on `approved`) and module nodes. A stubbed step stores `{dry_run: true}` under its
 * `storeAs`. No workflow instance is created; the sample record is never saved. Each step is visited once (a loop
 * body runs once), at most 200 steps.
 *
 * @package Cms\Core\Services\Workflow\Package
 * @since   2026-11-24
 */
final class WorkflowPackageDryRun
{
    /** Step types that run for real in a dry run: pure functions of the context. */
    public const EXECUTED = [
        'set_variable', 'copy_variable', 'clear_variable', 'decision', 'condition', 'switch', 'assert_condition',
        'aggregate_list', 'list_operation', 'render_template', 'text_transform', 'json_transform', 'datetime',
    ];

    private const MAX_STEPS = 200;

    /** Output a stubbed step continues on when it has named outputs. */
    private const HAPPY_OUTPUTS = ['approved', 'main', 'pass', 'true', 'each', 'acquired', 'ok', 'valid', 'batch', 'success'];

    /**
     * @param array<string, mixed> $row    WorkflowsSection row (bindings may still be placeholders)
     * @param array<string, mixed> $sample `{root: {...}, vars?: {...}}`
     * @return array{completed: bool, steps: list<array<string, mixed>>, vars: array<string, mixed>}
     */
    public function run(array $row, array $sample): array
    {
        $registry = NodeRegistry::current();
        $runners = app(StepRunnerRegistry::class);
        $upgrader = new StepConfigUpgrader();

        $steps = [];
        $start = null;
        foreach ((array) ($row['steps'] ?? []) as $step) {
            if (is_array($step) && is_string($step['step_code'] ?? null)) {
                $steps[$step['step_code']] = $step;
                if ($start === null && filter_var($step['is_start'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $start = $step['step_code'];
                }
            }
        }
        $start ??= array_key_first($steps);
        $edges = array_values(array_filter((array) ($row['connections'] ?? []), 'is_array'));

        $context = new WorkflowContext($this->sampleRoot((array) ($sample['root'] ?? [])), [], (array) ($sample['vars'] ?? []));
        $report = [];
        $queue = $start !== null ? [$start] : [];
        $seen = [];
        $completed = true;

        while ($queue !== [] && count($seen) < self::MAX_STEPS) {
            $code = array_shift($queue);
            if (isset($seen[$code]) || !isset($steps[$code])) {
                continue;
            }
            $seen[$code] = true;

            $step = $steps[$code];
            $type = (string) ($step['step_type'] ?? '');
            $config = is_array($step['step_config'] ?? null) ? $upgrader->upgrade($step['step_config'], $type)['config'] : [];
            $definition = $registry->get($type);
            $safety = $definition?->safetyFor($config) ?? ['unknown'];
            $entry = ['step_code' => $code, 'step_type' => $type, 'safety' => $safety];

            if (in_array($type, self::EXECUTED, true) && $runners->has($type)) {
                try {
                    $result = $runners->get($type)->run(new StepDefinition(null, null, $type, $code, $code, $step['name'] ?? null, $config), $context);
                } catch (Throwable $e) {
                    $report[] = $entry + ['mode' => 'executed', 'outcome' => 'failed', 'error' => $e->getMessage()];
                    $completed = false;
                    continue;
                }
                if ($result->status !== 'success') {
                    $report[] = $entry + ['mode' => 'executed', 'outcome' => $result->status, 'error' => (string) ($result->error['message'] ?? $result->status)];
                    $completed = $completed && $result->status === 'skipped';
                    continue;
                }
                $context->setStepResult($code, $result->resultData);
                $output = $result->output() ?? $result->nextEdgeHint;
                $report[] = $entry + ['mode' => 'executed', 'outcome' => 'success', 'output' => $output];
                array_push($queue, ...$this->next($code, $edges, $output, false));
                continue;
            }

            $outputs = array_values(array_diff($definition?->outputs ?? ['main'], ['error']));
            $output = count($outputs) > 1 ? (array_values(array_intersect(self::HAPPY_OUTPUTS, $outputs))[0] ?? $outputs[0]) : null;
            $stub = ['dry_run' => true, 'step_type' => $type] + ($type === 'approval' ? ['decision' => 'approved'] : []);
            if (is_string($config['storeAs'] ?? null) && str_starts_with($config['storeAs'], 'vars.')) {
                $context->setVarPath(substr($config['storeAs'], 5), $stub);
            }
            $context->setStepResult($code, $stub);
            $report[] = $entry + ['mode' => 'stubbed', 'outcome' => 'would_run', 'would' => $this->would($type, $safety), 'output' => $output];
            // A stubbed loop runs its body once and then continues after it.
            array_push($queue, ...$this->next($code, $edges, $output, $type === 'loop_each' || $type === 'split_in_batches'));
        }

        if ($queue !== []) {
            $completed = false;
        }

        return ['completed' => $completed, 'steps' => $report, 'vars' => $this->publicVars($context->getVars())];
    }

    /**
     * @param list<array<string, mixed>> $edges
     * @return list<string>
     */
    private function next(string $code, array $edges, ?string $output, bool $allOutputs): array
    {
        $outgoing = array_values(array_filter($edges, static fn (array $e): bool => ($e['from'] ?? null) === $code));
        $outgoing = array_values(array_filter($outgoing, fn (array $e): bool => $this->handle($e) !== 'error'));
        if ($outgoing === []) {
            return [];
        }
        if ($allOutputs) {
            return array_map(static fn (array $e): string => (string) $e['to'], $outgoing);
        }

        if ($output !== null) {
            $aliases = [$output, ...(match ($output) {
                'true' => ['yes'], 'yes' => ['true'], 'false' => ['no'], 'no' => ['false'], 'each' => ['loop'], 'loop' => ['each'], default => [],
            })];
            foreach (['match' => $aliases, 'default' => ['default']] as $handles) {
                $hit = array_values(array_filter($outgoing, fn (array $e): bool => in_array($this->handle($e), $handles, true)));
                if ($hit !== []) {
                    return [(string) $hit[0]['to']];
                }
            }
        }

        $plain = array_values(array_filter($outgoing, fn (array $e): bool => $this->handle($e) === null));
        if ($plain !== []) {
            return array_map(static fn (array $e): string => (string) $e['to'], $plain);
        }

        return count($outgoing) === 1 && $output === null ? [(string) $outgoing[0]['to']] : [];
    }

    /**
     * Output handle of a package connection (`source_handle`, or an `equals` condition value).
     *
     * @param array<string, mixed> $edge
     */
    private function handle(array $edge): ?string
    {
        $condition = is_array($edge['condition'] ?? null) ? $edge['condition'] : [];
        foreach (['source_handle', 'condition_value', 'condition'] as $key) {
            if (is_string($condition[$key] ?? null) && trim($condition[$key]) !== '') {
                return trim($condition[$key]);
            }
        }

        return null;
    }

    /**
     * @param list<string> $safety
     */
    private function would(string $type, array $safety): string
    {
        if ($type === 'approval') {
            return 'would pause for an approval; the dry run continues on approved';
        }
        $parts = [];
        foreach ($safety as $class) {
            $parts[] = match ($class) {
                'internal_write' => 'write site data',
                'external_call' => 'call an outside service',
                'ai_spend' => 'call an AI model (spends budget)',
                'publish' => 'publish',
                'read' => 'read data',
                default => $class,
            };
        }

        return 'would ' . implode(', ', array_unique($parts)) . " ({$type})";
    }

    /**
     * The sample record as an unsaved model: expressions read `root.<field>` from it; it is never saved.
     *
     * @param array<string, mixed> $attributes
     */
    private function sampleRoot(array $attributes): Model
    {
        $root = new class extends Model {
            protected $guarded = [];

            public function save(array $options = []): bool
            {
                return false;
            }
        };
        $root->forceFill($attributes);

        return $root;
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function publicVars(array $vars): array
    {
        unset($vars['__runtime']);

        return $vars;
    }
}
