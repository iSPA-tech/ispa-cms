<?php

namespace Cms\Core\Tests\Feature\Workflow;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Cms\Core\Tests\TestCase;

class WorkflowExpressionControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $user = new User();
        $user->setAttribute($user->getKeyName(), 2);
        $user->exists = true;
        Sanctum::actingAs($user, ['*']);
    }

    public function test_validate_expression_returns_error_on_invalid(): void
    {
        $res = $this->postJson('/api/v2/admin/workflow/expression/validate', [
            'expression' => 'root.',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ok', false);
    }

    public function test_preview_template_renders_with_mock_context(): void
    {
        $res = $this->postJson('/api/v2/admin/workflow/template/preview', [
            'template' => 'Hello {{ vars.name }}',
            'sampleVars' => ['name' => 'Bob'],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.rendered', 'Hello Bob');
    }

    public function test_allow_db_false_blocks_db_preview(): void
    {
        config(['core.workflow.allow_expression_db_preview' => false]);

        $res = $this->postJson('/api/v2/admin/workflow/expression/validate', [
            'expression' => 'root.id',
            'allowDb' => true,
            'targetType' => 'App\\Models\\User',
            'targetId' => 1,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.errors.0.message', 'Database-backed preview is not allowed');
    }
}
