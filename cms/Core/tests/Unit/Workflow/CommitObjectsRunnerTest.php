<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\Runners\CommitObjectsRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\TestCase;

class CommitObjectsRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('commit_customers');
        Schema::create('commit_customers', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_commit_single_model(): void
    {
        $customer = CommitCustomer::create(['email' => 'x@example.com']);
        $customer->email = 'y@example.com';

        $ctx = new WorkflowContext($this->makeRoot(), [], ['customer' => $customer]);
        $runner = new CommitObjectsRunner();
        $step = new StepDefinition(null, null, 'commit_objects', 'c', null, 'Commit', [
            'targetExpr' => 'vars.customer',
            'withEvents' => true,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertEquals(1, $res->resultData['committedCount']);
        $customer->refresh();
        $this->assertEquals('y@example.com', $customer->email);
    }

    public function test_commit_list_models(): void
    {
        $a = CommitCustomer::create(['email' => 'a@example.com']);
        $b = CommitCustomer::create(['email' => 'b@example.com']);
        $a->email = 'a2@example.com';
        $b->email = 'b2@example.com';

        $ctx = new WorkflowContext($this->makeRoot(), [], ['customers' => [$a, $b]]);
        $runner = new CommitObjectsRunner();
        $step = new StepDefinition(null, null, 'commit_objects', 'c', null, 'Commit', [
            'targetExpr' => 'vars.customers',
            'withEvents' => true,
        ]);

        $res = $runner->run($step, $ctx);

        $this->assertSame('success', $res->status);
        $this->assertEquals(2, $res->resultData['committedCount']);
        $this->assertEquals('a2@example.com', $a->fresh()->email);
        $this->assertEquals('b2@example.com', $b->fresh()->email);
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

class CommitCustomer extends Model
{
    use SoftDeletes;
    protected $table = 'commit_customers';
    protected $guarded = [];
}
