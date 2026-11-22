<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Workflows\Expression\Exceptions\TemplateRenderException;
use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Tests\TestCase;

class TemplateRendererTest extends TestCase
{
    public function test_render_string_with_multiple_blocks(): void
    {
        $root = $this->makeModel(123, ['name' => 'John']);
        $ctx = new WorkflowContext($root, ['event' => 'order.created'], ['foo' => 'bar']);
        $renderer = new TemplateRenderer();

        $tpl = 'Hello {{ root.id }} - {{ root.name }} - {{ vars.foo }}';
        $this->assertSame('Hello 123 - John - bar', $renderer->renderString($tpl, $ctx));
    }

    public function test_render_nested_array_config(): void
    {
        $root = $this->makeModel(1, ['name' => 'Alice']);
        $ctx = new WorkflowContext($root, [], ['nested' => ['value' => 42]]);
        $renderer = new TemplateRenderer();

        $config = [
            'title' => 'Hi {{ root.name }}',
            'payload' => [
                'v' => '{{ vars.nested.value }}',
            ],
        ];

        $rendered = $renderer->renderMixed($config, $ctx);

        $this->assertSame('Hi Alice', $rendered['title']);
        $this->assertSame('42', $rendered['payload']['v']);
    }

    public function test_render_null_and_arrays(): void
    {
        $root = $this->makeModel(2);
        $ctx = new WorkflowContext($root, [], ['list' => [1, 2]]);
        $renderer = new TemplateRenderer();

        $tpl = 'List: {{ vars.list }} | Missing: {{ vars.none }}';
        $out = $renderer->renderString($tpl, $ctx);

        $this->assertSame('List: [1,2] | Missing: ', $out);
    }

    public function test_invalid_expression_throws(): void
    {
        $this->expectException(TemplateRenderException::class);

        $root = $this->makeModel(3);
        $ctx = new WorkflowContext($root);
        $renderer = new TemplateRenderer();

        $renderer->renderString('Bad {{ unknown.fn() }}', $ctx);
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

        return $model;
    }
}
