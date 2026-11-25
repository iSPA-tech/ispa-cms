<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\AutoApproval\WorkflowAutoApproval;
use Cms\Core\Services\Workflow\Package\WorkflowPackageManifest;
use Cms\Core\Workflows\Nodes\NodeConfigValidator;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Runners\Human\ApprovalAssignment;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Cms\Core\Workflows\Runtime\StepTypeCatalog;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Server-side policy of a workflow graph (B4).
 *
 * draftProblems() holds the checks the designer save has always run (moved here from
 * WorkflowProjectController::validateGraph(), unchanged): unknown step types, the callable/model allowlist, the node
 * catalog (schema, `_v`, node permission, taint/approval lint) and approval assignees/SLA.
 *
 * It refuses `verdict_source_invalid`: an approval step whose `jev.verdict_from` is not the stored result (`storeAs`, or
 * `steps.<code>`) of a JEV judge step in the same graph (review 2026-11-24 finding 6: a verdict a `set_variable` writes
 * could approve anything).
 *
 * It also refuses `auto_approval_not_ready`: an approval step switched to auto-publish (`mode: jev` with
 * `jev.verdict_from`) while its JEV calibration is not ready (WorkflowAutoApproval). A step that is already on in the
 * published version is not refused again (its auto-pause guards it at run time), so an unrelated edit still saves.
 *
 * problems() adds what only publishing enforces:
 * - `host_not_allowlisted`: a literal outbound host (`url` of http_request, rss_read, sitemap_read, media_import) that
 *   is not on the site's allowlist (HttpHostAllowlist::knowsHost). URLs built from data stay a run-time check.
 * - `publish_needs_approval`: a node whose safety includes `publish` can be reached from the start without passing an
 *   approval node on an edge it takes once approved (`approved` or a plain edge; `expired`, `rejected`, `default`, ...
 *   lead on unreviewed), unless a Webmaster exempted that step (`workflow_projects.policy_exemptions`).
 * - `too_many_steps` (more than `core.workflow.max_steps`), `loop_limit_exceeded` (a loop `maxItems` above
 *   `core.workflow.max_loop_items`).
 *
 * The graph is the canonical shape of WorkflowVersionSnapshot: `steps` [{step_code, step_type, step_config, is_start}]
 * and `connections` [{from, to, condition}] (step codes; `condition` names the output handle the edge leaves from).
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
class WorkflowPublishPolicy
{
    /**
     * Every problem that blocks publishing the graph.
     *
     * @param array<string, mixed> $graph
     * @return list<array{code: string, message: string, step_code?: string}>
     */
    public function problems(WorkflowProject $project, array $graph, ?Authenticatable $user): array
    {
        return array_merge(
            $this->draftProblems($graph, $user, $project),
            $this->publishProblems($project, $graph),
        );
    }

    /**
     * The checks of a draft save (order: unknown types, refused callables/models, node catalog, approval assignees).
     *
     * @param array<string, mixed> $graph
     * @return list<array{code: string, message: string, step_code?: string}>
     */
    public function draftProblems(array $graph, ?Authenticatable $user, ?WorkflowProject $project = null): array
    {
        $guard = app(WorkflowCallableGuard::class);
        $errors = [];
        $catalogSteps = [];

        foreach ($this->steps($graph) as $step) {
            $stepCode = $step['step_code'];
            $stepType = $step['step_type'] ?? null;
            if (!is_string($stepType) || !StepTypeCatalog::isStorable($stepType)) {
                $errors[] = ['step_code' => $stepCode, 'code' => 'unknown_type', 'message' => $stepCode . ': unknown step type'];
                continue;
            }

            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];

            if ($stepType === 'blueprint_callable') {
                $class = $config['class'] ?? null;
                $type = $config['callableType'] ?? null;
                $method = $config['method'] ?? null;
                // A node not configured yet (no class) may be saved as a draft; it still fails at run time.
                if (is_string($class) && trim($class) !== '' && (!is_string($type)
                    || !$guard->allowsCallable($type, $class, is_string($method) ? $method : null))) {
                    $errors[] = ['step_code' => $stepCode, 'code' => 'class_not_allowed', 'message' => $stepCode . ': blueprint_callable: class not allowed'];
                }
            } elseif (in_array($stepType, ['retrieve', 'create_object'], true)
                && is_string($config['model'] ?? null) && !$guard->allowsModel($config['model'])) {
                $errors[] = ['step_code' => $stepCode, 'code' => 'model_not_allowed', 'message' => $stepCode . ': ' . $stepType . ': model not allowed'];
            }

            $catalogSteps[] = ['step_code' => $stepCode, 'step_type' => $stepType, 'step_config' => $config];
        }

        // Node catalog checks: config schema and version, the user's node permission, taint/approval lint.
        $nodeProblems = (new NodeConfigValidator())->validate($catalogSteps, $this->edges($graph), $user);
        foreach ($catalogSteps as $step) {
            if (in_array($step['step_type'], ['approval', 'ai_action'], true)) {
                $nodeProblems = array_merge($nodeProblems, ApprovalAssignment::configProblems($step['step_code'], $step['step_config']));
            }
        }

        return array_values(array_merge($errors, $nodeProblems, $this->verdictSourceProblems($catalogSteps), $this->autoApprovalProblems($catalogSteps, $project)));
    }

    /**
     * `verdict_source_invalid` for approval steps whose `jev.verdict_from` no JEV judge step of the graph produces.
     *
     * @param list<array{step_code: string, step_type: string, step_config: array<string, mixed>}> $steps
     * @return list<array{code: string, message: string, step_code: string}>
     */
    protected function verdictSourceProblems(array $steps): array
    {
        $problems = [];
        foreach ($steps as $step) {
            $from = $step['step_type'] === 'approval' ? WorkflowAutoApproval::verdictFrom($step['step_config']) : null;
            if ($from !== null && WorkflowAutoApproval::verdictProducers($from, $steps) === []) {
                $problems[] = [
                    'step_code' => $step['step_code'],
                    'code' => 'verdict_source_invalid',
                    'message' => "{$step['step_code']}: jev.verdict_from must be where a JEV review step of this workflow stores its verdict.",
                ];
            }
        }

        return $problems;
    }

    /**
     * `auto_approval_not_ready` for approval steps switched to auto-publish before the calibration allows it.
     *
     * @param list<array{step_code: string, step_type: string, step_config: array<string, mixed>}> $steps
     * @return list<array{code: string, message: string, step_code: string}>
     */
    protected function autoApprovalProblems(array $steps, ?WorkflowProject $project): array
    {
        $problems = [];
        $published = null;
        foreach ($steps as $step) {
            if ($step['step_type'] !== 'approval' || !WorkflowAutoApproval::isOn($step['step_config'])) {
                continue;
            }
            $published ??= $this->publishedApprovalConfigs($project);
            if (WorkflowAutoApproval::isOn($published[$step['step_code']] ?? [])) {
                continue;
            }

            $calibration = app(WorkflowAutoApproval::class)->calibrationFor($project, $step['step_code'], $step['step_config'], $steps);
            if (($calibration['ready'] ?? false) === true) {
                continue;
            }
            $rate = $calibration['agreement_rate'] ?? null;
            $problems[] = [
                'step_code' => $step['step_code'],
                'code' => 'auto_approval_not_ready',
                'message' => sprintf(
                    '%s: auto-publish can be turned on only after %d decisions by people with JEV agreeing at least %d%% of the time (now %d decisions, %s).',
                    $step['step_code'],
                    (int) ($calibration['required'] ?? WorkflowAutoApproval::requiredDecisions()),
                    (int) round(100 * (float) ($calibration['minimum_agreement'] ?? WorkflowAutoApproval::minimumAgreement())),
                    (int) ($calibration['decided'] ?? 0),
                    $rate === null ? 'no agreement yet' : round(100 * (float) $rate) . '% agreement',
                ),
            ];
        }

        return $problems;
    }

    /**
     * Approval step configs of the project's published version, by step code ([] when none).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function publishedApprovalConfigs(?WorkflowProject $project): array
    {
        $graph = $project?->publishedVersion?->graphData() ?? [];
        $configs = [];
        foreach ((array) ($graph['steps'] ?? []) as $step) {
            if (is_array($step) && ($step['step_type'] ?? null) === 'approval' && is_scalar($step['step_code'] ?? null)) {
                $configs[(string) $step['step_code']] = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            }
        }

        return $configs;
    }

    /**
     * Checks only publishing enforces.
     *
     * @param array<string, mixed> $graph
     * @return list<array{code: string, message: string, step_code?: string}>
     */
    public function publishProblems(WorkflowProject $project, array $graph): array
    {
        $problems = [];
        $steps = $this->steps($graph);

        $maxSteps = (int) config('core.workflow.max_steps', 200);
        if (count($steps) > $maxSteps) {
            $problems[] = ['code' => 'too_many_steps', 'message' => 'The workflow has ' . count($steps) . " steps; the limit is {$maxSteps}."];
        }

        $loopCap = (int) config('core.workflow.max_loop_items', 100);
        $allowlist = app(HttpHostAllowlist::class);
        $manifest = new WorkflowPackageManifest();
        $registry = NodeRegistry::current();
        $approvalTypes = $registry->approvalTypes();
        $exempt = $this->exemptStepCodes($project);
        $reachable = $this->reachableWithoutApproval($steps, $this->edges($graph), $approvalTypes);

        foreach ($steps as $step) {
            $code = $step['step_code'];
            $type = (string) ($step['step_type'] ?? '');
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];

            $maxItems = $config['maxItems'] ?? null;
            if ($type === 'loop_each' && is_numeric($maxItems) && (int) $maxItems > $loopCap) {
                $problems[] = ['step_code' => $code, 'code' => 'loop_limit_exceeded', 'message' => "{$code}: maxItems {$maxItems} is above the site limit of {$loopCap}."];
            }

            foreach ((array) ($manifest->derive(['steps' => [$step]], [])['outbound_hosts'] ?? []) as $host) {
                if (is_string($host) && $host !== '(expression)' && !$allowlist->knowsHost($host)) {
                    $problems[] = ['step_code' => $code, 'code' => 'host_not_allowlisted', 'message' => "{$code}: outbound host {$host} is not on this site's allowlist."];
                }
            }

            $safety = $registry->get($type)?->safetyFor($config) ?? [];
            if (in_array('publish', $safety, true) && !in_array($type, $approvalTypes, true)
                && isset($reachable[$code]) && !isset($exempt[$code])) {
                $problems[] = ['step_code' => $code, 'code' => 'publish_needs_approval', 'message' => "{$code}: publishes without an approval step before it."];
            }
        }

        return $problems;
    }

    /**
     * Whether the graph has at least one step that publishes and no such step is reachable from the start without
     * passing an approval node (the `publish_needs_approval` rule, exemptions not counted). The setup screen's summary.
     *
     * @param array<string, mixed> $graph
     */
    public function publishesOnlyAfterApproval(array $graph): bool
    {
        $registry = NodeRegistry::current();
        $approvalTypes = $registry->approvalTypes();
        $steps = $this->steps($graph);
        $reachable = $this->reachableWithoutApproval($steps, $this->edges($graph), $approvalTypes);

        $publishes = false;
        foreach ($steps as $step) {
            $type = (string) ($step['step_type'] ?? '');
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            if (in_array($type, $approvalTypes, true) || !in_array('publish', $registry->get($type)?->safetyFor($config) ?? [], true)) {
                continue;
            }
            if (isset($reachable[$step['step_code']])) {
                return false;
            }
            $publishes = true;
        }

        return $publishes;
    }

    /**
     * @return array<string, true> Step codes a Webmaster exempted from the approval rule.
     */
    protected function exemptStepCodes(WorkflowProject $project): array
    {
        $codes = [];
        foreach ((array) ($project->policy_exemptions ?? []) as $exemption) {
            if (is_array($exemption) && is_string($exemption['step_code'] ?? null) && $exemption['step_code'] !== '') {
                $codes[$exemption['step_code']] = true;
            }
        }

        return $codes;
    }

    /**
     * Steps reachable from a start step on a path that passes no approval node on an edge the approval takes once
     * approved (the start itself included).
     *
     * Starts: steps marked `is_start`, else steps without an incoming edge. Without any edge the steps follow each
     * other in order (the taint lint's convention).
     *
     * @param list<array<string, mixed>> $steps
     * @param list<array{from: string, to: string, handle?: string|null}> $edges
     * @param list<string> $approvalTypes
     * @return array<string, true>
     */
    protected function reachableWithoutApproval(array $steps, array $edges, array $approvalTypes): array
    {
        $types = [];
        foreach ($steps as $step) {
            $types[$step['step_code']] = (string) ($step['step_type'] ?? '');
        }
        if ($types === []) {
            return [];
        }

        $next = [];
        if ($edges === []) {
            $codes = array_keys($types);
            for ($i = 1; $i < count($codes); $i++) {
                $next[$codes[$i - 1]][] = ['to' => $codes[$i], 'handle' => null];
            }
            $starts = [$codes[0]];
        } else {
            $incoming = [];
            foreach ($edges as $edge) {
                $next[$edge['from']][] = ['to' => $edge['to'], 'handle' => $edge['handle'] ?? null];
                $incoming[$edge['to']] = true;
            }
            $starts = array_values(array_map(
                static fn (array $s): string => $s['step_code'],
                array_filter($steps, static fn (array $s): bool => (bool) ($s['is_start'] ?? false))
            ));
            if ($starts === []) {
                $starts = array_values(array_filter(array_keys($types), static fn (string $c): bool => !isset($incoming[$c])));
            }
        }

        $seen = [];
        $queue = $starts;
        while ($queue !== []) {
            $code = array_shift($queue);
            if (isset($seen[$code]) || !isset($types[$code])) {
                continue;
            }
            $seen[$code] = true;
            $approval = in_array($types[$code], $approvalTypes, true);
            foreach ($next[$code] ?? [] as $edge) {
                // An approval's `expired` / `rejected` / `default` edge leads on without anyone approving.
                if (!$approval || !NodeConfigValidator::passesApproval($edge['handle'])) {
                    $queue[] = $edge['to'];
                }
            }
        }

        return $seen;
    }

    /**
     * @param array<string, mixed> $graph
     * @return list<array<string, mixed>>
     */
    protected function steps(array $graph): array
    {
        $steps = [];
        foreach ((array) ($graph['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $code = $step['step_code'] ?? null;
            if (!is_scalar($code) || (string) $code === '') {
                continue;
            }
            $step['step_code'] = (string) $code;
            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $graph
     * @return list<array{from: string, to: string, handle: string|null}>
     */
    protected function edges(array $graph): array
    {
        $edges = [];
        foreach ((array) ($graph['connections'] ?? []) as $edge) {
            $from = is_array($edge) ? ($edge['from'] ?? null) : null;
            $to = is_array($edge) ? ($edge['to'] ?? null) : null;
            if (is_scalar($from) && is_scalar($to)) {
                $edges[] = ['from' => (string) $from, 'to' => (string) $to, 'handle' => NodeConfigValidator::edgeHandle($edge['condition'] ?? null)];
            }
        }

        return $edges;
    }
}
