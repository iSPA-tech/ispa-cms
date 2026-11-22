<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\ListOperationRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class ListOperationRunnerTest extends TestCase
{
    public function test_filter_operation(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVarPath('orders', [
            ['total' => 500],
            ['total' => 1500],
            ['total' => 2500],
        ]);

        $runner = new ListOperationRunner();
        $step = new StepDefinition(null, null, 'list_operation', 'op', null, 'Filter', [
            'collectionExpr' => 'vars.orders',
            'operation' => 'filter',
            'conditionExpr' => 'item.total > 1000',
            'storeAs' => 'vars.bigOrders',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $stored = $ctx->getVarPath('bigOrders');
        $this->assertCount(2, $stored);
        $this->assertSame(2, $res->resultData['count']);
    }

    public function test_map_operation(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVarPath('orders', [
            ['id' => 1, 'total' => 10],
            ['id' => 2, 'total' => 20],
        ]);

        $runner = new ListOperationRunner();
        $step = new StepDefinition(null, null, 'list_operation', 'op', null, 'Map', [
            'collectionExpr' => 'vars.orders',
            'operation' => 'map',
            'mapExpr' => "{'id': item.id, 't': item.total}",
            'storeAs' => 'vars.mapped',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $mapped = $ctx->getVarPath('mapped');
        $this->assertEquals([['id' => 1, 't' => 10], ['id' => 2, 't' => 20]], $mapped);
    }

    public function test_sort_and_take_operation(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVarPath('orders', [
            ['total' => 10],
            ['total' => 30],
            ['total' => 20],
        ]);

        $runner = new ListOperationRunner();
        $step = new StepDefinition(null, null, 'list_operation', 'op', null, 'Sort', [
            'collectionExpr' => 'vars.orders',
            'operation' => 'sort',
            'sortField' => 'total',
            'sortDir' => 'desc',
            'take' => 2,
            'storeAs' => 'vars.sorted',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $sorted = $ctx->getVarPath('sorted');
        $this->assertEquals([['total' => 30], ['total' => 20]], $sorted);
        $this->assertSame(2, $res->resultData['count']);
    }

    public function test_take_operation_only(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVarPath('orders', [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]);

        $runner = new ListOperationRunner();
        $step = new StepDefinition(null, null, 'list_operation', 'op', null, 'Take', [
            'collectionExpr' => 'vars.orders',
            'operation' => 'take',
            'take' => 1,
            'storeAs' => 'vars.first',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertCount(1, $ctx->getVarPath('first'));
    }

    public function test_invalid_store_as_fails(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new ListOperationRunner();
        $step = new StepDefinition(null, null, 'list_operation', 'op', null, 'Invalid', [
            'collectionExpr' => 'vars.orders',
            'operation' => 'filter',
            'conditionExpr' => 'item.total > 0',
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
