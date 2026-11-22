<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Runtime\StepState;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class WorkflowContextTest extends TestCase
{
    public function test_set_and_get_vars(): void
    {
        $root = $this->makeModel(1, ['name' => 'root']);
        $ctx = new WorkflowContext($root);

        $ctx->setVar('foo', 'bar');

        $this->assertTrue($ctx->hasVar('foo'));
        $this->assertSame('bar', $ctx->getVar('foo'));
        $this->assertSame('default', $ctx->getVar('missing', 'default'));
    }

    public function test_set_and_get_step_result(): void
    {
        $root = $this->makeModel(1);
        $ctx = new WorkflowContext($root);

        $ctx->setStepResult('step_a', ['ok' => true], 'success', ['meta' => 1]);

        $step = $ctx->getStep('step_a');
        $this->assertInstanceOf(StepState::class, $step);
        $this->assertSame(['ok' => true], $ctx->getStepResult('step_a'));
        $this->assertNull($ctx->getStepResult('step_missing'));
        $this->assertSame('success', $step?->getStatus());
    }

    public function test_safe_serialization_with_relations(): void
    {
        $parent = $this->makeModel(10, ['name' => 'parent']);
        $child = $this->makeModel(11, ['name' => 'child']);
        $parent->setRelation('child', $child);

        $ctx = new WorkflowContext($parent, ['event' => 'order.created']);
        $serialized = $ctx->toArray();

        $this->assertSame('order.created', $serialized['trigger']['event']);
        $this->assertSame(10, $serialized['root']['__id']);
        $this->assertSame('parent', $serialized['root']['attributes']['name']);
        $this->assertSame('child', $serialized['root']['relations']['child']['attributes']['name']);
    }

    public function test_recursion_guard_prevents_infinite_loop(): void
    {
        $first = $this->makeModel(1, ['name' => 'first']);
        $second = $this->makeModel(2, ['name' => 'second']);

        $first->setRelation('peer', $second);
        $second->setRelation('peer', $first);

        $ctx = new WorkflowContext($first);
        $serialized = $ctx->toArray();

        $peer = $serialized['root']['relations']['peer'];
        $this->assertSame(2, $peer['__id']);
        $this->assertSame(['__ref' => $first::class . ':1'], $peer['relations']['peer'] ?? null);
    }

    protected function makeModel(int $id, array $attributes = []): Model
    {
        $model = new class extends Model {
            protected $table = 'dummy';
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
}
