<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\LogMessageRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class LogMessageRunnerTest extends TestCase
{
    public function test_logs_rendered_message_and_context(): void
    {
        $root = $this->makeRoot(['id' => 99, 'total' => 123]);
        $ctx = new WorkflowContext($root);
        $runner = new LogMessageRunner();
        $step = new StepDefinition(null, null, 'log_message', 'log', null, 'Log', [
            'level' => 'info',
            'message' => 'Order {{ root.id }} processed',
            'context' => [
                'total' => '{{ root.total }}',
            ],
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertTrue($res->resultData['logged']);
        $log = $res->logs[0];
        $this->assertSame('log_message', $log['action']);
        $this->assertSame('info', $log['level']);
        $this->assertSame('Order 99 processed', $log['message']);
        $this->assertEquals(['total' => '123'], $log['context']);
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
