<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\WorkflowExecutor;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class WorkflowExecutorLogMessageTest extends TestCase
{
    public function test_log_message_step_runs_without_missing_handler_warning(): void
    {
        CMSLogger::spy();

        $project = new WorkflowProject();
        $project->id = 1;

        $input = new WorkflowStep([
            'id' => 1,
            'project_id' => 1,
            'step_code' => 'start',
            'name' => 'Start',
            'step_type' => 'input',
            'is_start' => true,
            'step_config' => ['node_type' => 'input'],
        ]);

        $logStep = new WorkflowStep([
            'id' => 2,
            'project_id' => 1,
            'step_code' => 'log',
            'name' => 'Log',
            'step_type' => 'script',
            'step_config' => [
                'level' => 'info',
                'message' => 'Hello {{ root.id }}',
                'context' => ['id' => '{{ root.id }}'],
            ],
        ]);

        $output = new WorkflowStep([
            'id' => 3,
            'project_id' => 1,
            'step_code' => 'end',
            'name' => 'End',
            'step_type' => 'output',
            'step_config' => ['node_type' => 'output'],
        ]);

        $project->setRelation('steps', collect([$input, $logStep, $output]));

        $conn1 = new WorkflowConnection([
            'id' => 1,
            'project_id' => 1,
            'from_step_id' => 1,
            'to_step_id' => 2,
            'condition' => ['source_handle' => 'output', 'target_handle' => 'input'],
        ]);
        $conn2 = new WorkflowConnection([
            'id' => 2,
            'project_id' => 1,
            'from_step_id' => 2,
            'to_step_id' => 3,
            'condition' => ['source_handle' => 'output', 'target_handle' => 'input'],
        ]);
        $project->setRelation('connections', collect([$conn1, $conn2]));

        $target = new class extends Model {
            protected $table = 'orders';
        };
        $target->setAttribute('id', 99);
        $target->exists = true;

        $executor = new WorkflowExecutor($project, $target);
        $executor->runGraph($project->steps, $project->connections);

        CMSLogger::shouldNotHaveReceived('warning');
    }
}
