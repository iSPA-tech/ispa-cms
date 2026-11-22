<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\AggregateListRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class AggregateListRunnerTest extends TestCase
{
    public function test_sum_aggregates_and_stores(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVarPath('items', [
            ['price' => 10],
            ['price' => 20],
            ['price' => null],
            ['price' => 'x'],
        ]);

        $runner = new AggregateListRunner();
        $step = new StepDefinition(null, null, 'aggregate_list', 'agg', null, 'Aggregate', [
            'collectionExpr' => 'vars.items',
            'operation' => 'sum',
            'field' => 'price',
            'storeAs' => 'vars.totalPrice',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertSame(30.0, $res->resultData['value']);
        $this->assertSame(2, $res->resultData['count']);
        $this->assertEquals(30.0, $ctx->getVarPath('totalPrice'));
    }

    public function test_count_aggregates_without_field(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVarPath('items', [['a' => 1], ['a' => 2], ['a' => 3]]);

        $runner = new AggregateListRunner();
        $step = new StepDefinition(null, null, 'aggregate_list', 'agg', null, 'Aggregate', [
            'collectionExpr' => 'vars.items',
            'operation' => 'count',
            'storeAs' => 'vars.count',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertSame(3, $res->resultData['value']);
        $this->assertSame(3, $res->resultData['count']);
        $this->assertEquals(3, $ctx->getVarPath('count'));
    }

    public function test_average_skips_non_numeric(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVarPath('items', [
            ['price' => 10],
            ['price' => 'x'],
            ['price' => 20],
        ]);

        $runner = new AggregateListRunner();
        $step = new StepDefinition(null, null, 'aggregate_list', 'agg', null, 'Aggregate', [
            'collectionExpr' => 'vars.items',
            'operation' => 'avg',
            'field' => 'price',
            'storeAs' => 'vars.avgPrice',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertSame(15.0, $res->resultData['value']);
        $this->assertSame(2, $res->resultData['count']);
    }

    public function test_invalid_store_as_fails(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new AggregateListRunner();
        $step = new StepDefinition(null, null, 'aggregate_list', 'agg', null, 'Aggregate', [
            'collectionExpr' => 'vars.items',
            'operation' => 'sum',
            'field' => 'price',
            'storeAs' => 'bad.path',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('failed', $res->status);
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
