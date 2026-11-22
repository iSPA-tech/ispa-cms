<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\BreakLoopRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class BreakLoopRunnerTest extends TestCase
{
    public function test_break_loop_sets_hint(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new BreakLoopRunner();
        $step = new StepDefinition(null, null, 'break_loop', 'break', null, 'Break', []);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertSame('break', $res->nextEdgeHint);
        $this->assertEquals(['action' => 'break'], $res->resultData);
        $this->assertEquals('break_loop', $res->logs[0]['action']);
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
