<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Ai\AiModelChoices;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;
use Cms\Core\Services\Ai\AiTaskRuntime;
use Cms\Core\Services\Ai\Usage\AiGateway;
use Cms\Core\Workflows\Runtime\Runners\Ai\AiPromptRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Model choice (2026-11-24): AiModelChoices lists provider + model pairs of keyed providers; AiTaskRuntime::run()
 * takes an override that must be on that list; AI nodes pick one with `model_choice` or a rewrite's `options.model`.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Records payloads; never calls a network. */
final class AmcFakeProvider implements AgenticAiProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function createCompletion(array $payload = []): array
    {
        $this->payloads[] = $payload;

        return ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
    }

    public function getContent(array $response = []): string
    {
        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }
}

/** Runtime without a provider factory: the fake answers every call, whatever provider is chosen. */
function amcRuntime(): AmcFakeProvider
{
    $fake = new AmcFakeProvider();
    app()->instance(AiTaskRuntime::class, new AiTaskRuntime(app(AiRuntimeConfigResolver::class), null, $fake, app(AiGateway::class)));

    return $fake;
}

function amcCtx(array $vars = []): WorkflowContext
{
    $root = new WorkflowProject();
    $root->id = 1;

    return new WorkflowContext($root, [], $vars);
}

beforeEach(function (): void {
    foreach (array_keys(AiRuntimeSettingsService::DRIVERS) as $driver) {
        config()->set("core.ai_structured_extraction.providers.{$driver}.api_key", '');
        config()->set("core.ai_structured_extraction.providers.{$driver}.model", '');
    }
    $settings = app(AiRuntimeSettingsService::class);
    $settings->saveProviders([
        ['key' => 'anthropic', 'model' => 'claude-custom-1', 'base_uri' => 'https://secret-gateway.example/v1', 'api_key' => 'sk-ant-SECRET-123'],
    ]);
    config()->set('core.ai_structured_extraction.providers.openai.api_key', 'sk-openai-ENV-456');
    $settings->saveTasks([['task' => 'workflow_ai_prompt', 'provider' => 'openai', 'model' => 'gpt-5.6-terra', 'fallback_models' => 'gpt-5.6-luna']]);
});

it('lists only keyed providers', function (): void {
    $choices = app(AiModelChoices::class)->selectable();
    $values = array_column($choices, 'value');

    expect(array_values(array_unique(array_column($choices, 'provider'))))->toBe(['anthropic', 'openai'])
        ->and($values)->toContain('anthropic:claude-custom-1', 'anthropic:claude-haiku-5-5', 'openai:gpt-5.6-terra', 'openai:gpt-5.6-luna')
        // Priced models stay under their own family; embeddings and unkeyed providers are left out.
        ->and($values)->not->toContain('openai:claude-haiku-5-5', 'anthropic:gpt-5.6-luna', 'openai:text-embedding-3-small', 'grok:grok-4.3')
        ->and(app(AiModelChoices::class)->isSelectable('grok:grok-4.3'))->toBeFalse()
        ->and(app(AiModelChoices::class)->parse('openai:gpt-5.6-luna'))->toBe(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    $haiku = collect($choices)->firstWhere('value', 'anthropic:claude-haiku-5-5');
    expect($haiku['provider_label'])->toBe('Anthropic (Claude)')
        ->and($haiku['price_known'])->toBeTrue()
        ->and(collect($choices)->firstWhere('value', 'anthropic:claude-custom-1')['price_known'])->toBeFalse();
});

it('never returns an api key', function (): void {
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'placeholder', 'username' => 'p-' . Str::random(6), 'email' => Str::random(6) . '@example.com',
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0,
    ]);
    $author = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'author', 'username' => 'a-' . Str::random(6), 'email' => Str::random(6) . '@example.com',
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0,
    ]);
    $outsider = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'outsider', 'username' => 'o-' . Str::random(6), 'email' => Str::random(6) . '@example.com',
        'password' => Hash::make('secret123'), 'type' => 0, 'level' => 0,
    ]);
    $role = Role::query()->create(['name' => 'Author ' . Str::random(6), 'custom' => ['level' => 50]]);
    $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => 'workflow_project', 'name' => 'viewAny'], ['title' => 'workflow_project.viewAny']));
    $author->roles()->attach($role);
    $this->withoutMiddleware();

    $this->actingAs($outsider->fresh(), 'sanctum');
    $this->getJson('/api/v2/admin/ai-runtime/selectable-models')->assertForbidden();

    $this->actingAs($author->fresh(), 'sanctum');
    $response = $this->getJson('/api/v2/admin/ai-runtime/selectable-models')->assertOk();
    $body = (string) $response->getContent();

    expect(array_column($response->json('data'), 'value'))->toContain('anthropic:claude-custom-1')
        ->and(array_keys($response->json('data.0')))->toBe(['value', 'provider', 'provider_label', 'model', 'price_known'])
        ->and($body)->not->toContain('SECRET')
        ->and($body)->not->toContain('ENV-456')
        ->and($body)->not->toContain('secret-gateway');
});

it('the override wins over the task row', function (): void {
    $fake = amcRuntime();

    $run = app(AiTaskRuntime::class)->run('workflow_ai_prompt', ['model' => 'x', 'messages' => []], null, ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5']);
    $plain = app(AiTaskRuntime::class)->run('workflow_ai_prompt', ['model' => 'x', 'messages' => []]);

    expect($fake->payloads[0]['model'])->toBe('claude-haiku-5-5')
        ->and($run['model'])->toBe('claude-haiku-5-5')
        ->and($fake->payloads[1]['model'])->toBe('gpt-5.6-terra')
        ->and($plain['model'])->toBe('gpt-5.6-terra');
});

it('an unselectable override fails the call', function (): void {
    $fake = amcRuntime();

    expect(fn () => app(AiTaskRuntime::class)->run('workflow_ai_prompt', ['messages' => []], null, ['provider' => 'grok', 'model' => 'grok-4.3']))
        ->toThrow(RuntimeException::class, 'grok:grok-4.3')
        ->and(fn () => app(AiTaskRuntime::class)->run('workflow_ai_prompt', ['messages' => []], null, ['provider' => 'openai', 'model' => 'gpt-made-up']))
        ->toThrow(RuntimeException::class, 'openai:gpt-made-up');
    expect($fake->payloads)->toBe([]);
});

it('an AI node model_choice is used', function (): void {
    $fake = amcRuntime();
    $step = new StepDefinition(null, 1, 'ai_prompt', 's1', 's1', 'Step', ['instructions' => 'Hi', 'model_choice' => 'anthropic:claude-haiku-5-5']);

    $result = (new AiPromptRunner())->run($step, amcCtx());
    expect($result->status)->toBe('success')
        ->and($fake->payloads[0]['model'])->toBe('claude-haiku-5-5');

    // A stale choice fails the step visibly, without a call.
    $stale = new StepDefinition(null, 1, 'ai_prompt', 's1', 's1', 'Step', ['instructions' => 'Hi', 'model_choice' => 'grok:grok-4.3']);
    $failed = (new AiPromptRunner())->run($stale, amcCtx());
    expect($failed->status)->toBe('failed')
        ->and(json_encode($failed->error))->toContain('grok:grok-4.3')
        ->and($fake->payloads)->toHaveCount(1);
});

it('a rewrite model option wins over model_choice', function (): void {
    $fake = amcRuntime();
    $config = ['instructions' => 'Write', 'model_choice' => 'anthropic:claude-haiku-5-5', 'revision' => 'vars.approval'];
    $step = new StepDefinition(null, 1, 'ai_prompt', 's1', 's1', 'Step', $config);

    (new AiPromptRunner())->run($step, amcCtx(['approval' => ['decision' => 'changes_requested', 'feedback' => 'Shorter', 'options' => ['model' => 'openai:gpt-5.6-luna']]]));
    // An option that is not selectable is ignored: model_choice applies.
    (new AiPromptRunner())->run($step, amcCtx(['approval' => ['decision' => 'changes_requested', 'feedback' => 'Shorter', 'options' => ['model' => 'grok:grok-4.3']]]));

    expect($fake->payloads[0]['model'])->toBe('gpt-5.6-luna')
        ->and($fake->payloads[0]['messages'][1]['content'])->not->toContain('model: openai')
        ->and($fake->payloads[1]['model'])->toBe('claude-haiku-5-5');
});
