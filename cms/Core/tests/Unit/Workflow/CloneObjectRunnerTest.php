<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\CloneObjectRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Tests\TestCase;

class CloneObjectRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('clone_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function test_clone_without_save(): void
    {
        $source = CloneCustomer::create(['name' => 'Alice']);
        $ctx = new WorkflowContext($this->makeRoot(), [], [
            'customer' => $source,
        ]);

        $runner = new CloneObjectRunner();
        $step = new StepDefinition(null, null, 'clone_object', 'clone', null, 'Clone', [
            'sourceExpr' => 'vars.customer',
            'exclude' => ['id', 'created_at', 'updated_at'],
            'save' => false,
            'storeAs' => 'vars.copy',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $copy = $ctx->getVarPath('copy');
        $this->assertInstanceOf(CloneCustomer::class, $copy);
        $this->assertNull($copy->getKey());
        $this->assertEquals('Alice', $copy->name);
        $this->assertFalse($res->resultData['saved']);
    }

    public function test_clone_with_save(): void
    {
        $source = CloneCustomer::create(['name' => 'Bob']);
        $ctx = new WorkflowContext($this->makeRoot(), [], [
            'customer' => $source,
        ]);

        $runner = new CloneObjectRunner();
        $step = new StepDefinition(null, null, 'clone_object', 'clone', null, 'Clone', [
            'sourceExpr' => 'vars.customer',
            'save' => true,
            'storeAs' => 'vars.copy',
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $copy = $ctx->getVarPath('copy');
        $this->assertInstanceOf(CloneCustomer::class, $copy);
        $this->assertNotNull($copy->getKey());
        $this->assertNotEquals($source->getKey(), $copy->getKey());
        $this->assertTrue($res->resultData['saved']);
        $this->assertEquals($copy->getKey(), $res->resultData['id']);
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

class CloneCustomer extends Model
{
    protected $table = 'clone_customers';
    protected $guarded = [];
}
