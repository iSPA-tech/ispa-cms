<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\ThrowExceptionRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class ThrowExceptionRunnerTest extends TestCase
{
    public function test_throw_exception_fails_step(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new ThrowExceptionRunner();
        $step = new StepDefinition(null, null, 'throw_exception', 'throw', null, 'Throw', [
            'message' => 'Stop here',
            'code' => 400,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('failed', $res->status);
        $this->assertSame('Stop here', $res->error['message']);
        $this->assertSame(400, $res->error['code']);
        $this->assertSame('throw_exception', $res->logs[0]['action']);
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
