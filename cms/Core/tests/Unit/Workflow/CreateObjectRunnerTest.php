<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\CreateObjectRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\TestCase;

class CreateObjectRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('co_logs');
        Schema::create('co_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    public function test_save_false_creates_instance(): void
    {
        $ctx = new WorkflowContext($this->makeRoot(['id' => 11]));
        $runner = new CreateObjectRunner();
        $step = new StepDefinition(null, null, 'create_object', 'c', null, 'Create', [
            'model' => COL::class,
            'fields' => [
                ['field' => 'order_id', 'valueExpr' => 'root.id'],
                ['field' => 'status', 'valueExpr' => "'created'"],
            ],
            'save' => false,
            'storeAs' => 'vars.log',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertFalse($res->resultData['saved']);
        $stored = $ctx->getVarPath('log');
        $this->assertInstanceOf(COL::class, $stored);
        $this->assertEquals(11, $stored->order_id);
        $this->assertNull($res->resultData['id']);
    }

    public function test_save_true_persists_and_returns_id(): void
    {
        $ctx = new WorkflowContext($this->makeRoot(['id' => 22]));
        $runner = new CreateObjectRunner();
        $step = new StepDefinition(null, null, 'create_object', 'c', null, 'Create', [
            'model' => COL::class,
            'fields' => [
                ['field' => 'order_id', 'valueExpr' => 'root.id'],
                ['field' => 'status', 'valueExpr' => "'created'"],
            ],
            'save' => true,
            'storeAs' => 'vars.log',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertTrue($res->resultData['saved']);
        $this->assertNotNull($res->resultData['id']);
        $stored = $ctx->getVarPath('log');
        $this->assertInstanceOf(COL::class, $stored);
        $this->assertEquals($res->resultData['id'], $stored->getKey());
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

class COL extends Model
{
    protected $table = 'co_logs';
    protected $guarded = [];
}
