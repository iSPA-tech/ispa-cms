<?php

declare(strict_types=1);

use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiStructuredExtractionService;
use Cms\Core\Services\Ai\JsonSchemaShapeValidator;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
});

function aiRuntimeSetting(string $key, string $value, string $type = 'text'): void
{
    Setting::query()->create([
        'setting_key' => $key,
        'type' => $type,
        'group_name' => 'core.ai_runtime',
        'name' => mb_substr($key, -50),
        'input_setting' => [
            'title' => $key,
            'isPublic' => $type !== 'password',
            'isEnv' => false,
        ],
        'value' => $value,
    ]);
}

it('returns null overrides when the registry has no rows', function (): void {
    $resolver = new AiRuntimeConfigResolver();

    expect($resolver->taskOverrides('vehicle_comparison_editorial_coach_draft'))
        ->toBe(['provider' => null, 'model' => null, 'temperature' => null, 'reasoning_effort' => null, 'fallback_models' => []])
        ->and($resolver->taskOverrides(''))
        ->toBe(['provider' => null, 'model' => null, 'temperature' => null, 'reasoning_effort' => null, 'fallback_models' => []]);
});

it('reads task provider, model and temperature from settings', function (): void {
    aiRuntimeSetting('core.ai_runtime.tasks.acme_draft.provider', 'anthropic');
    aiRuntimeSetting('core.ai_runtime.tasks.acme_draft.model', 'claude-sonnet-5');
    aiRuntimeSetting('core.ai_runtime.tasks.acme_draft.temperature', '0.2');

    $overrides = (new AiRuntimeConfigResolver())->taskOverrides('ACME_DRAFT');

    expect($overrides)->toBe([
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'temperature' => 0.2,
        'reasoning_effort' => null,
        'fallback_models' => [],
    ]);
});

it('reads a stored task reasoning effort lower-cased and ignores values outside the supported set', function (): void {
    aiRuntimeSetting('core.ai_runtime.tasks.effort_task.reasoning_effort', 'HIGH');
    aiRuntimeSetting('core.ai_runtime.tasks.bad_effort_task.reasoning_effort', 'turbo');
    aiRuntimeSetting('core.ai_runtime.tasks.blank_effort_task.reasoning_effort', '');

    $resolver = new AiRuntimeConfigResolver();

    expect($resolver->taskOverrides('effort_task')['reasoning_effort'])->toBe('high')
        ->and($resolver->taskOverrides('bad_effort_task')['reasoning_effort'])->toBeNull()
        ->and($resolver->taskOverrides('blank_effort_task')['reasoning_effort'])->toBeNull();

    foreach (['none', 'minimal', 'low', 'medium', 'high', 'xhigh'] as $effort) {
        aiRuntimeSetting("core.ai_runtime.tasks.task_{$effort}.reasoning_effort", $effort);
    }
    $fresh = new AiRuntimeConfigResolver();

    foreach (['none', 'minimal', 'low', 'medium', 'high', 'xhigh'] as $effort) {
        expect($fresh->taskOverrides("task_{$effort}")['reasoning_effort'])->toBe($effort);
    }
});

it('applies stored provider credentials onto runtime config with alias mapping', function (): void {
    aiRuntimeSetting('core.ai_runtime.providers.anthropic.model', 'claude-opus-5');
    aiRuntimeSetting('core.ai_runtime.providers.anthropic.api_key', 'sk-test-registry', 'password');
    aiRuntimeSetting('core.ai_runtime.providers.anthropic.base_uri', 'https://proxy.example/v1/');

    config()->set('core.ai_structured_extraction.providers.anthropic.model', 'env-model');
    (new AiRuntimeConfigResolver())->applyProviderRuntimeOverrides('claude');

    expect(config('core.ai_structured_extraction.providers.anthropic.model'))->toBe('claude-opus-5')
        ->and(config('core.ai_structured_extraction.providers.anthropic.api_key'))->toBe('sk-test-registry')
        ->and(config('core.ai_structured_extraction.providers.anthropic.base_uri'))->toBe('https://proxy.example/v1/');
});

it('routes extractions through the registry default provider when no task override exists', function (): void {
    aiRuntimeSetting('core.ai_runtime.default_provider', 'anthropic');
    aiRuntimeSetting('core.ai_runtime.providers.anthropic.model', 'claude-registry-model');

    $provider = new AiRuntimeRecordingProvider();
    $service = new AiStructuredExtractionService(
        $provider,
        new JsonSchemaShapeValidator(),
        null,
        new AiRuntimeConfigResolver(),
    );

    $result = $service->extract(
        input: '{"probe":true}',
        schema: [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['ok'],
            'properties' => ['ok' => ['type' => 'boolean']],
        ],
        instructions: 'Return ok true.',
        context: ['module' => 'Core', 'task' => 'default_provider_demo'],
        options: [
            'module' => 'Core',
            'task' => 'default_provider_demo',
            'schema_name' => 'default_provider_demo',
            'schema_version' => 'v1',
            'cache_enabled' => false,
            'store' => false,
        ],
    );

    expect($result->ok)->toBeTrue()
        ->and($provider->payloads[0]['model'])->toBe('claude-registry-model');
});

it('lets admin task settings win over hardcoded call-site options', function (): void {
    aiRuntimeSetting('core.ai_runtime.tasks.legacy_hardcoded_task.provider', 'agentic_ai');
    aiRuntimeSetting('core.ai_runtime.tasks.legacy_hardcoded_task.model', 'admin-chosen-model');
    aiRuntimeSetting('core.ai_runtime.tasks.legacy_hardcoded_task.temperature', '0.1');

    $provider = new AiRuntimeRecordingProvider();
    $service = new AiStructuredExtractionService(
        $provider,
        new JsonSchemaShapeValidator(),
        null,
        new AiRuntimeConfigResolver(),
    );

    $service->extract(
        input: '{"probe":true}',
        schema: [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['ok'],
            'properties' => ['ok' => ['type' => 'boolean']],
        ],
        instructions: 'Return ok true.',
        context: ['module' => 'Core', 'task' => 'legacy_hardcoded_task'],
        options: [
            'module' => 'Core',
            'task' => 'legacy_hardcoded_task',
            'schema_name' => 'legacy_hardcoded_task',
            'schema_version' => 'v1',
            'cache_enabled' => false,
            'store' => false,
            'model' => 'legacy-env-model',
            'temperature' => 0.0,
        ],
    );

    expect($provider->payloads[0]['model'])->toBe('admin-chosen-model')
        ->and($provider->payloads[0]['temperature'])->toBe(0.1);
});

it('lets the admin task reasoning effort win over the call-site option and the provider default', function (): void {
    // Pin the provider so the test does not depend on AI_STRUCTURED_EXTRACTION_PROVIDER in the local .env.
    config()->set('core.ai_structured_extraction.provider', 'agentic_ai');
    config()->set('core.ai_structured_extraction.providers.agentic_ai.reasoning_effort', 'minimal');
    aiRuntimeSetting('core.ai_runtime.tasks.effort_demo.reasoning_effort', 'high');

    $provider = new AiRuntimeRecordingProvider();
    $service = new AiStructuredExtractionService(
        $provider,
        new JsonSchemaShapeValidator(),
        null,
        new AiRuntimeConfigResolver(),
    );
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];
    $options = [
        'module' => 'Core',
        'schema_version' => 'v1',
        'cache_enabled' => false,
        'store' => false,
    ];

    $service->extract('{"probe":true}', $schema, 'Return ok true.', ['module' => 'Core', 'task' => 'effort_demo'], [
        ...$options,
        'task' => 'effort_demo',
        'schema_name' => 'effort_demo',
        'reasoning_effort' => 'low',
    ]);
    // A task without an admin row keeps the call-site option, then falls back to the provider default.
    $service->extract('{"probe":true}', $schema, 'Return ok true.', ['module' => 'Core', 'task' => 'effort_unset'], [
        ...$options,
        'task' => 'effort_unset',
        'schema_name' => 'effort_unset',
        'reasoning_effort' => 'low',
    ]);
    $service->extract('{"probe":true}', $schema, 'Return ok true.', ['module' => 'Core', 'task' => 'effort_unset'], [
        ...$options,
        'task' => 'effort_unset',
        'schema_name' => 'effort_unset',
    ]);

    expect($provider->payloads[0]['reasoning_effort'])->toBe('high')
        ->and($provider->payloads[1]['reasoning_effort'])->toBe('low')
        ->and($provider->payloads[2]['reasoning_effort'])->toBe('minimal');
});

it('resolves direct-caller runtime with admin-first precedence', function (): void {
    $resolver = new AiRuntimeConfigResolver();

    // Unconfigured registry: caller's legacy values pass through.
    expect($resolver->runtimeFor('acme_pipeline_translate', 'openai', 'gpt-4o-mini'))
        ->toBe(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'temperature' => null]);

    // Admin task row wins over the caller fallbacks.
    aiRuntimeSetting('core.ai_runtime.tasks.acme_pipeline_translate.provider', 'deepseek');
    aiRuntimeSetting('core.ai_runtime.tasks.acme_pipeline_translate.model', 'deepseek-v4-pro');
    $fresh = new AiRuntimeConfigResolver();

    expect($fresh->runtimeFor('acme_pipeline_translate', 'openai', 'gpt-4o-mini'))
        ->toBe(['provider' => 'deepseek', 'model' => 'deepseek-v4-pro', 'temperature' => null]);
});

it('runs direct completions through AiTaskRuntime with legacy passthrough and admin override', function (): void {
    $provider = new AiRuntimeRecordingProvider();

    // Unconfigured registry: payload passes through untouched.
    $runtime = new \Cms\Core\Services\Ai\AiTaskRuntime(new AiRuntimeConfigResolver());
    $result = $runtime->run('acme_translate_blocks', ['model' => 'gpt-5.4-mini', 'messages' => []], $provider);
    expect($result['model'])->toBe('gpt-5.4-mini')
        ->and($result['provider'])->toBe('legacy')
        ->and($provider->payloads[0]['model'])->toBe('gpt-5.4-mini');

    // Admin task row overrides model + temperature on the legacy provider.
    aiRuntimeSetting('core.ai_runtime.tasks.acme_translate_blocks.model', 'admin-model');
    aiRuntimeSetting('core.ai_runtime.tasks.acme_translate_blocks.temperature', '0.4');
    $runtime = new \Cms\Core\Services\Ai\AiTaskRuntime(new AiRuntimeConfigResolver());
    $result = $runtime->run('acme_translate_blocks', ['model' => 'gpt-5.4-mini', 'messages' => []], $provider);
    expect($result['model'])->toBe('admin-model')
        ->and($provider->payloads[1]['model'])->toBe('admin-model')
        ->and($provider->payloads[1]['temperature'])->toBe(0.4);
});

it('routes an extraction through the task-configured model with call-site options winning', function (): void {
    aiRuntimeSetting('core.ai_runtime.tasks.registry_demo.model', 'registry-model-x');
    aiRuntimeSetting('core.ai_runtime.tasks.registry_demo.temperature', '0.7');

    $provider = new AiRuntimeRecordingProvider();
    $service = new AiStructuredExtractionService(
        $provider,
        new JsonSchemaShapeValidator(),
        null,
        new AiRuntimeConfigResolver(),
    );
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['ok'],
        'properties' => ['ok' => ['type' => 'boolean']],
    ];

    $first = $service->extract(
        input: '{"probe":true}',
        schema: $schema,
        instructions: 'Return ok true.',
        context: ['module' => 'Core', 'task' => 'registry_demo'],
        options: [
            'module' => 'Core',
            'task' => 'registry_demo',
            'schema_name' => 'registry_demo',
            'schema_version' => 'v1',
            'cache_enabled' => false,
            'store' => false,
        ],
    );

    expect($first->ok)->toBeTrue()
        ->and($provider->payloads[0]['model'])->toBe('registry-model-x')
        ->and($provider->payloads[0]['temperature'])->toBe(0.7);

    $service->extract(
        input: '{"probe":true}',
        schema: $schema,
        instructions: 'Return ok true.',
        context: ['module' => 'Core', 'task' => 'registry_demo'],
        options: [
            'module' => 'Core',
            'task' => 'registry_demo',
            'schema_name' => 'registry_demo',
            'schema_version' => 'v1',
            'cache_enabled' => false,
            'store' => false,
            'model' => 'call-site-model',
            'temperature' => 0.0,
        ],
    );

    // Admin-first precedence: the configured task beats the call-site option.
    expect($provider->payloads[1]['model'])->toBe('registry-model-x')
        ->and($provider->payloads[1]['temperature'])->toBe(0.7);
});

final class AiRuntimeRecordingProvider implements AgenticAiProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function createCompletion(array $payload = []): array
    {
        $this->payloads[] = $payload;

        return [
            'choices' => [[
                'message' => ['content' => '{"ok":true}'],
            ]],
        ];
    }

    public function getContent(array $response = []): string
    {
        return '{"ok":true}';
    }
}
