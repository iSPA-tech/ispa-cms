<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Workflow;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Legacy workflow executor that traverses a WorkflowProject's step graph.
 *
 * Walks the project's steps via DFS starting from the is_start step,
 * evaluating decision conditions via Symfony ExpressionLanguage,
 * dispatching blueprint_callable handlers, rendering log_message steps,
 * and logging output steps. Uses adjacency list from WorkflowConnections.
 *
 * @see WorkflowProject
 * @see WorkflowContext
 * @package Cms\Core\Services\Workflow
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowExecutor
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected ExpressionLanguage $expressionLanguage;
    protected TemplateRenderer $templateRenderer;
    protected WorkflowContext $context;

    /**
     * Initialize the executor with a workflow project and its target model.
     *
     * @param WorkflowProject $workflow The workflow project defining the step graph.
     * @param Model           $target   The root Eloquent model the workflow operates on.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected WorkflowProject $workflow,
        protected Model $target
    ) {
        $this->expressionLanguage = new ExpressionLanguage();
        $this->templateRenderer = new TemplateRenderer();
        $this->context = new WorkflowContext($this->target, trigger: [], vars: [], steps: []);
    }

    /**
     * Execute the workflow by traversing the step graph from the start step.
     *
     * @return void
     */
    public function run(): void
    {
        $steps = $this->workflow->steps->keyBy('id');
        $connections = $this->workflow->connections;

        if ($steps->isEmpty()) {
            CMSLogger::debug([
                'message' => 'Workflow skipped: no steps',
                'workflow_id' => $this->workflow->id,
                'target_type' => $this->target->getMorphClass(),
                'target_id' => $this->target->getKey(),
            ]);
            return;
        }

        $start = $steps->first(function ($step) {
            return (bool) ($step->is_start ?? false);
        }) ?? $steps->first();

        CMSLogger::info([
            'message' => 'Workflow started',
            'workflow_id' => $this->workflow->id,
            'target_type' => $this->target->getMorphClass(),
            'target_id' => $this->target->getKey(),
            'start_step' => $start?->step_code,
        ]);

        $adj = [];
        foreach ($connections as $conn) {
            if ($conn->from_step_id && $conn->to_step_id) {
                $condition = is_array($conn->condition) ? $conn->condition : [];
                $adj[$conn->from_step_id][] = [
                    'to' => $conn->to_step_id,
                    'handle' => $condition['source_handle'] ?? null,
                    'condition' => $condition['condition'] ?? null,
                ];
            }
        }

        $stack = [$start->id];
        $visited = [];

        while ($stack) {
            $currentId = array_pop($stack);
            if (isset($visited[$currentId])) {
                continue;
            }
            $visited[$currentId] = true;

            $step = $steps[$currentId] ?? null;
            if (!$step) {
                continue;
            }

            $nextIds = $this->handleStep($step, $adj[$currentId] ?? []);

            foreach ($nextIds as $nextId) {
                if (!isset($visited[$nextId])) {
                    $stack[] = $nextId;
                }
            }
        }
    }

    protected function handleStep($step, array $edges): array
    {
        $stepType = $this->normalizeStepType($step);

        switch ($stepType) {
            case 'input':
                // Start node, nothing to execute.
                break;
            case 'decision':
                return $this->evaluateDecisionStep($step, $edges);
            case 'blueprint_callable':
                $this->executeHandler($step);
                break;
            case 'log_message':
                $this->executeLogMessage($step);
                break;
            case 'output':
                $this->logOutputStep($step);
                return [];
            default:
                CMSLogger::warning([
                    'message' => 'Workflow step type not handled explicitly',
                    'workflow_id' => $this->workflow->id,
                    'step' => $step->step_code,
                    'step_type' => $step->step_type,
                    'normalized_step_type' => $stepType,
                ]);
                break;
        }

        return collect($edges)
            ->pluck('to')
            ->filter()
            ->values()
            ->all();
    }

    protected function executeHandler($step): void
    {
        $config = $step->step_config ?? [];

        $class = $config['class'] ?? null;
        $method = $config['method'] ?? null;
        $payload = $config['payload'] ?? [];

        if (!$class || !$method) {
            CMSLogger::warning([
                'message' => 'Workflow blueprint_callable step missing handler',
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
            ]);
            return;
        }

        // Try to resolve short class names to known namespace
        $resolvedClass = $class;
        if (!class_exists($resolvedClass)) {
            $candidate = 'App\\Services\\Logger\\' . ltrim($class, '\\');
            if (class_exists($candidate)) {
                $resolvedClass = $candidate;
            }
        }

        if (!class_exists($resolvedClass)) {
            CMSLogger::error([
                'message' => 'Workflow step handler class not found',
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'class' => $class,
            ]);
            return;
        }

        try {
            $instance = app()->make($resolvedClass);
            // Invoke with common signature (data, target) for backward compatibility.
            call_user_func([$instance, $method], $payload, $this->target);
            CMSLogger::info([
                'message' => 'Workflow step executed',
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'handler' => $resolvedClass . '@' . $method,
            ]);
        } catch (\Throwable $e) {
            Log::error('WorkflowExecutor step failed', [
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'error' => $e->getMessage(),
            ]);
            CMSLogger::error([
                'message' => 'Workflow step failed',
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'handler' => $resolvedClass . '@' . $method,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function executeLogMessage($step): void
    {
        $config = $step->step_config ?? [];
        $level = $config['level'] ?? 'info';
        $message = $config['message'] ?? '';
        $contextData = is_array($config['context'] ?? null) ? $config['context'] : [];

        try {
            // Render message/context using existing template renderer and workflow context.
            $renderedMessage = $this->templateRenderer->renderString((string) $message, $this->context);
            $renderedContext = $this->templateRenderer->renderMixed($contextData, $this->context);

            CMSLogger::send([
                'message' => $renderedMessage,
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'context' => $renderedContext,
            ], $level);
        } catch (\Throwable $e) {
            // Fallback to raw logging to avoid breaking the workflow.
            CMSLogger::warning([
                'message' => 'Workflow log_message step failed to render; logging raw message',
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'error' => $e->getMessage(),
                // TODO: migrate to dedicated StepRunner once executor is fully aligned
            ]);
            CMSLogger::send([
                'message' => $message,
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'context' => $contextData,
            ], $level);
        }
    }

    protected function logOutputStep($step): void
    {
        CMSLogger::info([
            'message' => 'Workflow reached output step',
            'workflow_id' => $this->workflow->id,
            'step' => $step->step_code,
        ]);
    }

    /**
     * Normalize legacy step types to expected runtime handlers.
     */
    protected function normalizeStepType($step): string
    {
        $original = $step->step_type ?? '';
        $config = is_array($step->step_config) ? $step->step_config : [];
        $nodeType = $config['node_type'] ?? null;
        $code = $step->step_code ?? '';

        // 1) event + input
        if ($original === 'event' && $nodeType === 'input') {
            return 'input';
        }

        // 2) output node
        if ($nodeType === 'output') {
            return 'output';
        }

        // 3) decision stays
        if ($original === 'decision') {
            return 'decision';
        }

        $hasHandler = !empty($config['class']) && !empty($config['method']);
        $hasLogKeys = array_key_exists('level', $config) && array_key_exists('message', $config);

        // 4) log_message
        if ($code === 'log_message' || ($hasLogKeys && !$hasHandler)) {
            return 'log_message';
        }

        // 5) set_variable
        if ($original === 'set_variable') {
            return 'set_variable';
        }

        // 6) retrieve
        $hasRetrieve = array_key_exists('where', $config) || array_key_exists('model', $config) || array_key_exists('first', $config) || array_key_exists('limit', $config);
        if ($code === 'retrieve' || $hasRetrieve) {
            return 'retrieve';
        }

        // 7) change
        $hasChange = array_key_exists('targetExpr', $config) || array_key_exists('changes', $config) || array_key_exists('save', $config);
        if ($code === 'change' || $hasChange) {
            return 'change';
        }

        // 8) blueprint callable
        if ($hasHandler) {
            return 'blueprint_callable';
        }

        // 9) fallback warning
        CMSLogger::warning([
            'message' => 'Workflow step type not recognized; using original',
            'workflow_id' => $this->workflow->id,
            'step_id' => $step->id ?? null,
            'step_code' => $code,
            'step_type' => $original,
            'config_keys' => array_keys($config),
        ]);

        return $original ?: 'unknown';
    }

    /**
     * Evaluate a decision node and return the next step ids based on the truthy branch.
     *
     * @param  \Cms\Core\Models\Workflows\WorkflowStep  $step
     * @param  array  $edges
     * @return array<int>
     */
    protected function evaluateDecisionStep($step, array $edges): array
    {
        $config = $step->step_config ?? [];
        $conditionRaw = (string) ($config['condition'] ?? '');

        $expression = $this->normalizeCondition($conditionRaw);
        $yesHandle = $config['yes_output'] ?? 'target-a';
        $noHandle = $config['no_output'] ?? 'target-b';

        $context = [
            'order' => $this->target,
            '$order' => $this->target,
            'data' => $this->target->toArray(),
            'target' => $this->target,
            'today' => now()->toDateString(),
            'now' => now(),
        ];

        $result = false;

        try {
            $result = (bool) $this->expressionLanguage->evaluate($expression, $context);
        } catch (\Throwable $e) {
            CMSLogger::error([
                'message' => 'Decision condition evaluation failed',
                'workflow_id' => $this->workflow->id,
                'step' => $step->step_code,
                'condition' => $conditionRaw,
                'error' => $e->getMessage(),
            ]);
        }

        $chosenHandle = $result ? $yesHandle : $noHandle;

        $matching = collect($edges)
            ->filter(function ($edge) use ($chosenHandle) {
                return ($edge['handle'] ?? null) === $chosenHandle;
            })
            ->pluck('to')
            ->filter()
            ->values()
            ->all();

        // Fallback: if no edge matched the chosen handle, try all outgoing edges
        if (empty($matching) && !empty($edges)) {
            return collect($edges)
                ->pluck('to')
                ->filter()
                ->values()
                ->all();
        }

        return $matching;
    }

    /**
     * ExpressionLanguage variables cannot start with `$`, so strip `$` prefixes.
     */
    protected function normalizeCondition(string $expression): string
    {
        $clean = preg_replace('/\$(\w+)/', '$1', $expression) ?? $expression;
        $clean = str_replace(['===', '!=='], ['==', '!='], $clean);

        return trim($clean);
    }
}
