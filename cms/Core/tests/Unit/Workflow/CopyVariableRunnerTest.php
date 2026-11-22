<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\CopyVariableRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class CopyVariableRunnerTest extends TestCase
{
    public function test_copies_value_to_var_path(): void
    {
        $root = $this->makeRoot(['customer_email' => 'a@example.com']);
        $ctx = new WorkflowContext($root, [], ['customer' => ['email' => 'old@example.com']]);
        $runner = new CopyVariableRunner();
        $step = new StepDefinition(null, null, 'copy_variable', 'c', null, 'Copy', [
            'fromExpr' => 'root.customer_email',
            'to' => 'vars.customer.email',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertEquals('a@example.com', $ctx->getVarPath('customer.email'));
        $this->assertEquals(['from' => 'root.customer_email', 'to' => 'vars.customer.email'], $res->resultData);
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
