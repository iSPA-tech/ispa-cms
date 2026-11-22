<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\WorkflowExecutor;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class WorkflowExecutorNormalizationTest extends TestCase
{
    public function test_output_node_with_script_type_stops_without_handler_warning(): void
    {
        CMSLogger::spy();

        $project = new WorkflowProject();
        $project->id = 10;

        $input = new WorkflowStep([
            'id' => 1,
            'project_id' => 10,
            'step_code' => 'start',
            'name' => 'Start',
            'step_type' => 'event',
            'is_start' => true,
            'step_config' => ['node_type' => 'input'],
        ]);

        $output = new WorkflowStep([
            'id' => 2,
            'project_id' => 10,
            'step_code' => 'end',
            'name' => 'End',
            'step_type' => 'script',
            'step_config' => ['node_type' => 'output'],
        ]);

        $project->setRelation('steps', collect([$input, $output]));

        $conn = new WorkflowConnection([
            'id' => 1,
            'project_id' => 10,
            'from_step_id' => 1,
            'to_step_id' => 2,
            'condition' => ['source_handle' => 'output', 'target_handle' => 'input'],
        ]);
        $project->setRelation('connections', collect([$conn]));

        $target = new class extends Model {
            protected $table = 'orders';
        };
        $target->exists = true;
        $target->setAttribute('id', 1);

        $executor = new WorkflowExecutor($project, $target);
        $executor->run();

        CMSLogger::shouldNotHaveReceived('warning');
    }

    public function test_blueprint_callable_from_script_executes_handler(): void
    {
        CMSLogger::spy();
        DummyWorkflowHandler::$called = 0;
        app()->bind(DummyWorkflowHandler::class, fn() => new DummyWorkflowHandler());
        $this->assertTrue(class_exists(DummyWorkflowHandler::class));

        $project = new WorkflowProject();
        $project->id = 11;

        $input = new WorkflowStep([
            'id' => 1,
            'project_id' => 11,
            'step_code' => 'start',
            'name' => 'Start',
            'step_type' => 'event',
            'is_start' => true,
            'step_config' => ['node_type' => 'input'],
        ]);

        $callable = new WorkflowStep([
            'id' => 2,
            'project_id' => 11,
            'step_code' => 'call',
            'name' => 'Call',
            'step_type' => 'script',
            'step_config' => [
                'class' => DummyWorkflowHandler::class,
                'method' => 'handle',
                'payload' => ['foo' => 'bar'],
            ],
        ]);

        $project->setRelation('steps', collect([$input, $callable]));

        $conn = new WorkflowConnection([
            'id' => 1,
            'project_id' => 11,
            'from_step_id' => 1,
            'to_step_id' => 2,
            'condition' => ['source_handle' => 'output', 'target_handle' => 'input'],
        ]);
        $project->setRelation('connections', collect([$conn]));

        $target = new class extends Model {
            protected $table = 'orders';
        };
        $target->exists = true;
        $target->setAttribute('id', 2);

        $executor = new WorkflowExecutor($project, $target);
        $refMethod = (new \ReflectionClass(WorkflowExecutor::class))->getMethod('normalizeStepType');
        $refMethod->setAccessible(true);
        $this->assertSame('blueprint_callable', $refMethod->invoke($executor, $callable));
        $executor->run();

        $this->assertSame(1, DummyWorkflowHandler::$called);
    }
}

class DummyWorkflowHandler
{
    public static int $called = 0;

    public function handle($data = null, $target = null): void
    {
        self::$called++;
    }
}
