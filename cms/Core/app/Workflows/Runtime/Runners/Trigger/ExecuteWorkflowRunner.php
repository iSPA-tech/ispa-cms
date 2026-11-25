<?php

namespace Cms\Core\Workflows\Runtime\Runners\Trigger;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Services\Workflow\Triggers\SubWorkflowService;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggerRefused;
use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * `execute_workflow`: runs another project (start node `trigger_sub_workflow`) as a child run.
 *
 * Config: `project` (id or slug), `input` (key => expression), `mode` = `wait` (default: pause as `awaiting_child`
 * and continue with the child's result in `storeAs` once it finishes; a child that does not complete fails this run)
 * or `fire` (store {instance_id} and go on), `storeAs` (default `vars.child`). Loop and depth limits: TriggerChain.
 */
class ExecuteWorkflowRunner implements StepRunner
{
    public function __construct(protected SubWorkflowService $subWorkflows)
    {
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $mode = ($config['mode'] ?? 'wait') === 'fire' ? 'fire' : 'wait';
        $storeAs = (string) ($config['storeAs'] ?? 'vars.child');
        if (! str_starts_with($storeAs, 'vars.') || strlen($storeAs) <= 5 || WorkflowContext::isRuntimePath(substr($storeAs, 5))) {
            return StepRunResult::failed('execute_workflow: storeAs must be a vars.* path');
        }
        $storePath = substr($storeAs, 5);

        $parent = WorkflowInstance::query()->find((int) $context->getVarPath('__runtime.instance_id', 0));
        if (! $parent) {
            return StepRunResult::failed('execute_workflow: the calling run was not found');
        }

        try {
            $child = $this->subWorkflows->resolveChild($config['project'] ?? '');
            $input = $this->input((array) ($config['input'] ?? []), $context);
            $instance = $this->subWorkflows->start($parent, $child, $input, $mode, $storePath);
        } catch (WorkflowTriggerRefused $e) {
            return StepRunResult::failed('execute_workflow: ' . $e->reason);
        } catch (\Throwable $e) {
            return StepRunResult::failed('execute_workflow: ' . $e->getMessage());
        }

        if ($mode === 'fire') {
            $result = ['instance_id' => $instance->id, 'status' => 'started'];
            $context->setVarPath($storePath, $result);

            return StepRunResult::success($result);
        }

        // A child that already ended (e.g. a sync queue) is answered at once.
        $instance->refresh();
        if (SubWorkflowService::isFinished($instance)) {
            $result = $this->subWorkflows->result($instance);
            $context->setVarPath($storePath, $result);

            return $instance->status === 'completed'
                ? StepRunResult::success($result)
                : StepRunResult::failed('sub_workflow_failed: ' . $instance->status);
        }

        $context->setVarPath(SubWorkflowService::AWAITING_VAR, ['instance_id' => $instance->id, 'store_path' => $storePath]);

        return StepRunResult::paused(SubWorkflowService::AWAITING_STATUS, ['instance_id' => $instance->id], [
            ['level' => 'info', 'message' => "Waiting for sub-workflow run {$instance->id}"],
        ]);
    }

    /**
     * @param array<string|int, mixed> $map
     * @return array<string, mixed>
     */
    protected function input(array $map, WorkflowContext $context): array
    {
        $engine = new WorkflowExpressionEngine();
        $input = [];
        foreach ($map as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $input[$key] = is_string($value) && trim($value) !== '' ? $engine->evaluate($value, $context, true) : $value;
        }

        return $input;
    }
}
