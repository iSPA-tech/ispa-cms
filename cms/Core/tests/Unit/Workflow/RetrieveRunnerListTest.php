<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\RetrieveRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\Support\WorkflowTestDelegate;
use Cms\Core\Tests\TestCase;

class RetrieveRunnerListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('rr_customers');
        Schema::create('rr_customers', function (Blueprint $table) {
            $table->id();
            $table->string('email');
        });
        RRCustomer::create(['email' => 'a@example.com']);
        RRCustomer::create(['email' => 'b@example.com']);
        WorkflowTestDelegate::allow(RRCustomer::class);
    }

    public function test_retrieve_list_mode_counts(): void
    {
        $ctx = new WorkflowContext($this->makeRoot());
        $runner = new RetrieveRunner();
        $step = new StepDefinition(null, null, 'retrieve', 'r', null, 'Retrieve', [
            'model' => RRCustomer::class,
            'where' => [
                ['field' => 'email', 'op' => 'in', 'valueExpr' => "['a@example.com','b@example.com']"],
            ],
            'first' => false,
            'storeAs' => 'vars.customers',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertEquals(2, $res->resultData['count']);
        $this->assertTrue($res->resultData['found']);
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

class RRCustomer extends Model
{
    protected $table = 'rr_customers';
    protected $guarded = [];
    public $timestamps = false;
}
