<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\AssertConditionRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class AssertConditionRunnerTest extends TestCase
{
    public function test_assertion_passes(): void
    {
        $root = $this->makeRoot(['total' => 10]);
        $ctx = new WorkflowContext($root);
        $runner = new AssertConditionRunner();
        $step = new StepDefinition(null, null, 'assert_condition', 'assert', null, 'Assert', [
            'expression' => 'root.total > 0',
            'message' => 'Total must be positive',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertTrue($res->resultData['asserted']);
        $this->assertTrue($res->logs[0]['passed']);
    }

    public function test_assertion_fails(): void
    {
        $root = $this->makeRoot(['total' => -1]);
        $ctx = new WorkflowContext($root);
        $runner = new AssertConditionRunner();
        $step = new StepDefinition(null, null, 'assert_condition', 'assert', null, 'Assert', [
            'expression' => 'root.total > 0',
            'message' => 'Total must be positive',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('failed', $res->status);
        $this->assertFalse($res->error === null);
        $this->assertFalse($res->logs[0]['passed']);
    }

    protected function makeRoot(array $attrs = []): Model
    {
        $model = new class extends Model {
            protected $table = 'dummy';
            protected $guarded = [];
        };
        $model->setAttribute('id', 1);
        foreach ($attrs as $k => $v) {
            $model->setAttribute($k, $v);
        }
        $model->exists = true;
        return $model;
    }
}
