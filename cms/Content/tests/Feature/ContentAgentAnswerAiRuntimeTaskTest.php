<?php

declare(strict_types=1);

use Cms\Content\Database\Seeders\ContentAiRuntimeTaskSeeder;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiRuntimeSettingsService;
use Cms\Core\Services\Ai\AiTaskCatalog;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    Setting::query()
        ->where('setting_key', 'like', 'core.ai_runtime.tasks.content_agent_answer.%')
        ->forceDelete();
});

it('registers one visible blank content answer task without fallback configuration', function (): void {
    (new ContentAiRuntimeTaskSeeder())->run();
    (new ContentAiRuntimeTaskSeeder())->run();

    $overview = (new AiRuntimeSettingsService(new AiRuntimeConfigResolver()))->overview();
    $row = collect($overview['tasks'])->firstWhere('task', 'content_agent_answer');

    expect($row)->toMatchArray([
        'task' => 'content_agent_answer',
        'provider' => '',
        'model' => '',
        'temperature' => null,
        'runs' => 0,
    ])->and(
        Setting::query()
            ->where('setting_key', 'core.ai_runtime.tasks.content_agent_answer.provider')
            ->count(),
    )->toBe(1)
        ->and(
            Setting::query()
                ->where('setting_key', 'core.ai_runtime.tasks.content_agent_answer.model')
                ->count(),
        )->toBe(0);
});

it('labels the content answer task in the Content module catalog', function (): void {
    $label = config('content.ai_runtime_tasks.content_agent_answer');

    expect($label)->toBeString()->not->toBe('')
        ->and((new AiTaskCatalog())->all()['content_agent_answer'] ?? null)
        ->toBe(['label' => trim($label), 'module' => 'Content']);
});
