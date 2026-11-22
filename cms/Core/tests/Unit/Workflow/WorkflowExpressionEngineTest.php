<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Expression\Exceptions\ExpressionValidationException;
use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\StepState;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class WorkflowExpressionEngineTest extends TestCase
{
    public function test_access_root_and_vars(): void
    {
        $root = $this->makeOrder(5, ['created_at' => '2024-01-02 03:04:05']);
        $ctx = new WorkflowContext($root, ['event' => 'order.created'], ['foo' => 'bar']);

        $engine = new WorkflowExpressionEngine();

        $this->assertSame(5, $engine->evaluate('root.id', $ctx));
        $this->assertSame('bar', $engine->evaluate('vars.foo', $ctx));
    }

    public function test_access_nested_relation_and_steps(): void
    {
        $customer = $this->makeCustomer(10, ['name' => 'Alice']);
        $root = $this->makeOrder(1);
        $root->setRelation('customer', $customer);

        $steps = [
            'sendDiscord' => new StepState('sendDiscord', 'success', ['message_id' => 'abc123']),
        ];

        $ctx = new WorkflowContext($root, ['event' => 'order.created'], [], $steps);
        $engine = new WorkflowExpressionEngine();

        $this->assertSame('Alice', $engine->evaluate('root.customer.name', $ctx));
        $this->assertSame('abc123', $engine->evaluate('steps.sendDiscord.result.message_id', $ctx));
    }

    public function test_access_trigger(): void
    {
        $root = $this->makeOrder(2);
        $ctx = new WorkflowContext($root, ['event' => 'order.created', 'meta' => ['foo' => 'bar']]);
        $engine = new WorkflowExpressionEngine();

        $this->assertSame('order.created', $engine->evaluate('trigger.event', $ctx));
        $this->assertSame('bar', $engine->evaluate('trigger.meta.foo', $ctx));
    }

    public function test_helpers(): void
    {
        $root = $this->makeOrder(9, ['created_at' => '2024-02-03 04:05:06']);
        $ctx = new WorkflowContext($root, [], ['list' => [1, 2, 3]]);
        $engine = new WorkflowExpressionEngine();

        $this->assertSame(3, $engine->evaluate('len(vars.list)', $ctx));
        $this->assertTrue($engine->evaluate("contains('hello', 'ell')", $ctx));
        $this->assertTrue($engine->evaluate("in('a', ['a','b'])", $ctx));
        $this->assertSame('2024', $engine->evaluate("formatDate(root.created_at, 'Y')", $ctx));
        $this->assertSame('fallback', $engine->evaluate("coalesce(vars.missing, 'fallback')", $ctx));
        $this->assertInstanceOf(Carbon::class, $engine->evaluate('now()', $ctx));
    }

    public function test_validation_blocks_disallowed_functions(): void
    {
        $engine = new WorkflowExpressionEngine();

        $errors = $engine->validate('system("rm -rf /")');

        $this->assertNotEmpty($errors);
    }

    protected function makeOrder(int $id, array $attributes = []): Model
    {
        $model = new class extends Model {
            protected $table = 'orders';
            protected $guarded = [];
        };

        $model->setAttribute($model->getKeyName(), $id);
        foreach ($attributes as $key => $value) {
            $model->setAttribute($key, $value);
        }
        $model->exists = true;
        $model->setRelation('items', new Collection());

        return $model;
    }

    protected function makeCustomer(int $id, array $attributes = []): Model
    {
        $model = new class extends Model {
            protected $table = 'customers';
            protected $guarded = [];
        };

        $model->setAttribute($model->getKeyName(), $id);
        foreach ($attributes as $key => $value) {
            $model->setAttribute($key, $value);
        }
        $model->exists = true;

        return $model;
    }
}
