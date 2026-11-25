<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\DeleteObjectRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\Support\WorkflowTestDelegate;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Security\WorkflowModelRefs;

class DeleteObjectRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('do_customers');
        Schema::create('do_customers', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->softDeletes();
            $table->timestamps();
        });
        WorkflowTestDelegate::allow(DOCustomer::class);
    }

    public function test_single_model_soft_delete(): void
    {
        $customer = DOCustomer::create(['email' => 'x@example.com']);
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVar('customer', WorkflowModelRefs::nestedSnapshot($customer, $ctx));
        $runner = new DeleteObjectRunner();
        $step = new StepDefinition(null, null, 'delete_object', 'del', null, 'Delete', [
            'targetExpr' => 'vars.customer',
            'force' => false,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertEquals(1, $res->resultData['deletedCount']);
        $this->assertSoftDeleted('do_customers', ['id' => $customer->id]);
    }

    public function test_list_delete(): void
    {
        $a = DOCustomer::create(['email' => 'a@example.com']);
        $b = DOCustomer::create(['email' => 'b@example.com']);
        $ctx = new WorkflowContext($this->makeRoot());
        $ctx->setVar('customers', [WorkflowModelRefs::nestedSnapshot($a, $ctx), WorkflowModelRefs::nestedSnapshot($b, $ctx)]);
        $runner = new DeleteObjectRunner();
        $step = new StepDefinition(null, null, 'delete_object', 'del', null, 'Delete', [
            'targetExpr' => 'vars.customers',
            'force' => false,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertEquals(2, $res->resultData['deletedCount']);
        $this->assertSoftDeleted('do_customers', ['id' => $a->id]);
        $this->assertSoftDeleted('do_customers', ['id' => $b->id]);
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

class DOCustomer extends Model
{
    use SoftDeletes;

    protected $table = 'do_customers';
    protected $guarded = [];
}
