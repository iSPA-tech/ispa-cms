<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\ClearVariableRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class ClearVariableRunnerTest extends TestCase
{
    public function test_clears_variables_and_ignores_missing(): void
    {
        $ctx = new WorkflowContext($this->makeRoot(), [], [
            'customer' => ['id' => 1],
            'tempData' => ['foo' => 'bar'],
        ]);
        $runner = new ClearVariableRunner();
        $step = new StepDefinition(null, null, 'clear_variable', 'c', null, 'Clear', [
            'targets' => ['vars.customer', 'vars.missing', 'vars.tempData'],
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertArrayNotHasKey('customer', $ctx->getVars());
        $this->assertArrayNotHasKey('tempData', $ctx->getVars());
        $this->assertEquals(['cleared' => ['vars.customer', 'vars.missing', 'vars.tempData']], $res->resultData);
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
