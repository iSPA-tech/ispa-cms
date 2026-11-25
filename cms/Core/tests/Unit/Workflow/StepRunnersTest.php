<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\ChangeRunner;
use Cms\Core\Workflows\Runtime\Runners\DecisionRunner;
use Cms\Core\Workflows\Runtime\Runners\RetrieveRunner;
use Cms\Core\Workflows\Runtime\Runners\SetVariableRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\Support\WorkflowTestDelegate;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Security\WorkflowModelRefs;

class StepRunnersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('runner_customers', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->boolean('vip')->default(false);
            $table->timestamps();
        });
        WorkflowTestDelegate::allow(RunnerCustomer::class);
    }

    public function test_set_variable_assigns_nested_vars(): void
    {
        $ctx = new WorkflowContext($this->makeDummyRoot());
        $runner = new SetVariableRunner();
        $step = new StepDefinition(null, null, 'set_variable', 'code', null, 'Set Var', [
            'assignments' => [
                ['key' => 'vars.foo', 'valueExpr' => '1 + 2'],
                ['key' => 'vars.nested.bar', 'valueExpr' => 'root.id'],
            ],
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertEquals(3, $ctx->getVarPath('foo'));
        $this->assertEquals($ctx->getRoot()->id, $ctx->getVarPath('nested.bar'));
        $this->assertEquals(2, $res->resultData['count']);
        $this->assertEquals('vars.foo', $res->resultData['assigned'][0]['key']);
        $this->assertEquals('vars.nested.bar', $res->resultData['assigned'][1]['key']);
    }

    public function test_decision_returns_yes_hint(): void
    {
        $ctx = new WorkflowContext($this->makeDummyRoot(['total' => 2000]));
        $runner = new DecisionRunner();
        $step = new StepDefinition(null, null, 'decision', 'd', null, 'Decision', [
            'expression' => 'root.total > 1000',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertSame('yes', $res->nextEdgeHint);
        $this->assertTrue($res->resultData['value']);
    }

    public function test_retrieve_stores_snapshot_and_vars(): void
    {
        $customer = RunnerCustomer::create(['email' => 'test@example.com', 'vip' => false]);

        $ctx = new WorkflowContext($this->makeDummyRoot(['customer_email' => $customer->email]));
        $runner = new RetrieveRunner();
        $step = new StepDefinition(null, null, 'retrieve', 'r', null, 'Retrieve', [
            'model' => RunnerCustomer::class,
            'where' => [
                ['field' => 'email', 'op' => '==', 'valueExpr' => 'root.customer_email'],
            ],
            'first' => true,
            'storeAs' => 'vars.customer',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $snapshot = $ctx->getVarPath('customer');
        $this->assertEquals($customer->id, $snapshot['__id']);
        $this->assertEquals(1, $res->resultData['count']);
        $this->assertTrue($res->resultData['found']);
    }

    public function test_change_updates_model(): void
    {
        $customer = RunnerCustomer::create(['email' => 'c@example.com', 'vip' => false]);
        // The target is a reference this run produced (as a retrieve stores it), signed for the run.
        $ctx = new WorkflowContext($this->makeDummyRoot());
        $ctx->setVar('customer', WorkflowModelRefs::nestedSnapshot($customer, $ctx));
        $runner = new ChangeRunner();
        $step = new StepDefinition(null, null, 'change', 'c', null, 'Change', [
            'targetExpr' => 'vars.customer',
            'changes' => [
                ['field' => 'vip', 'valueExpr' => 'true'],
            ],
            'save' => true,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $customer->refresh();
        $this->assertTrue((bool) $customer->vip);
        $this->assertTrue($res->resultData['saved']);
        $this->assertEquals(RunnerCustomer::class, $res->resultData['model']);
        $this->assertEquals($customer->id, $res->resultData['id']);
    }

    public function test_change_without_save(): void
    {
        $customer = RunnerCustomer::create(['email' => 'd@example.com', 'vip' => false]);
        // The target is a reference this run produced (as a retrieve stores it), signed for the run.
        $ctx = new WorkflowContext($this->makeDummyRoot());
        $ctx->setVar('customer', WorkflowModelRefs::nestedSnapshot($customer, $ctx));
        $runner = new ChangeRunner();
        $step = new StepDefinition(null, null, 'change', 'c', null, 'Change', [
            'targetExpr' => 'vars.customer',
            'changes' => [
                ['field' => 'vip', 'valueExpr' => 'true'],
            ],
            'save' => false,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertFalse($res->resultData['saved']);
        $customer->refresh();
        $this->assertFalse((bool) $customer->vip); // not saved
    }

    public function test_change_invalid_target_fails(): void
    {
        $ctx = new WorkflowContext($this->makeDummyRoot());
        $runner = new ChangeRunner();
        $step = new StepDefinition(null, null, 'change', 'c', null, 'Change', [
            'targetExpr' => 'vars.unknown',
            'changes' => [
                ['field' => 'vip', 'valueExpr' => 'true'],
            ],
            'save' => true,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('failed', $res->status);
    }

    public function test_change_refuses_an_unsigned_snapshot(): void
    {
        $customer = RunnerCustomer::create(['email' => 'e@example.com', 'vip' => false]);
        $ctx = new WorkflowContext($this->makeDummyRoot(), [], [
            'customer' => ['__class' => RunnerCustomer::class, '__id' => $customer->id],
        ]);
        $step = new StepDefinition(null, null, 'change', 'c', null, 'Change', [
            'targetExpr' => 'vars.customer',
            'changes' => [['field' => 'vip', 'valueExpr' => 'true']],
        ]);

        $res = (new ChangeRunner())->run($step, $ctx);

        $this->assertSame('failed', $res->status);
        $this->assertFalse((bool) $customer->fresh()->vip);
    }

    protected function makeDummyRoot(array $attributes = []): Model
    {
        $model = new class extends Model {
            protected $table = 'dummy';
            protected $guarded = [];
        };
        $model->setAttribute('id', 1);
        foreach ($attributes as $k => $v) {
            $model->setAttribute($k, $v);
        }
        $model->exists = true;
        return $model;
    }
}

class RunnerCustomer extends Model
{
    protected $table = 'runner_customers';
    protected $guarded = [];
}
