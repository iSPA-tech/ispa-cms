<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\LoopEachRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class LoopEachRunnerTest extends TestCase
{
    public function test_iterates_and_sets_next_edge_hint(): void
    {
        $root = $this->makeRoot(['items' => [10, 20]]);
        $ctx = new WorkflowContext($root);
        $runner = new LoopEachRunner();
        $step = new StepDefinition(null, null, 'loop_each', 'loop', null, 'Loop', [
            'collectionExpr' => 'root.items',
            'itemVar' => 'vars.item',
            'indexVar' => 'vars.index',
        ]);

        $res1 = $runner->run($step, $ctx);
        $this->assertSame('loop', $res1->nextEdgeHint);
        $this->assertSame(10, $ctx->getVarPath('item'));
        $this->assertSame(0, $ctx->getVarPath('index'));
        $this->assertEquals(['index' => 0, 'total' => 2], $res1->resultData['state']);

        $res2 = $runner->run($step, $ctx);
        $this->assertSame('done', $res2->nextEdgeHint);
        $this->assertSame(20, $ctx->getVarPath('item'));
        $this->assertSame(1, $ctx->getVarPath('index'));
        $this->assertEquals(2, $res2->resultData['iterations']);
        $this->assertEquals(['index' => 1, 'total' => 2], $res2->resultData['state']);
    }

    public function test_empty_collection_returns_done(): void
    {
        $root = $this->makeRoot(['items' => []]);
        $ctx = new WorkflowContext($root);
        $runner = new LoopEachRunner();
        $step = new StepDefinition(null, null, 'loop_each', 'loop', null, 'Loop', [
            'collectionExpr' => 'root.items',
            'itemVar' => 'vars.item',
        ]);

        $res = $runner->run($step, $ctx);
        $this->assertSame('success', $res->status);
        $this->assertSame('done', $res->nextEdgeHint);
        $this->assertEquals(0, $res->resultData['iterations']);
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
