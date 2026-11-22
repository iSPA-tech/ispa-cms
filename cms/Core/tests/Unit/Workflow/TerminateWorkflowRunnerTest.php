<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\TerminateWorkflowRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class TerminateWorkflowRunnerTest extends TestCase
{
    public function test_terminate_success(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new TerminateWorkflowRunner();
        $step = new StepDefinition(null, null, 'terminate_workflow', 't', null, 'Terminate', [
            'status' => 'success',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertTrue($res->resultData['terminated']);
        $this->assertSame('success', $res->resultData['status']);
    }

    protected function makeRoot(): Model
    {
        $model = new class extends Model {
            protected $table = 'dummy';
            protected $guarded = [];
        };
        $model->setAttribute('id', 1);
        $model->exists = true;
        return $model;
    }
}
