<?php

use App\Services\Moderation\AutoModerationService;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    foreach (['2026_10_21_100000_create_core_ai_usage_daily_table', '2026_10_21_110000_add_via_to_core_ai_usage_daily_table'] as $migration) {
        (require base_path('cms/Core/database/migrations/' . $migration . '.php'))->up();
    }
    DB::table('core_ai_usage_daily')->delete();
    config()->set('openai.api_key', 'test-key');
});

it('meters the moderation call as auto_moderation and keeps the verdict', function (): void {
    OpenAI::fake([CreateResponse::fake([
        'model' => 'gpt-3.5-turbo',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'FAIL|Quảng cáo sai sự thật']]],
        'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 4, 'total_tokens' => 34],
    ])]);

    $result = (new AutoModerationService())->moderate(['name' => 'Xe đẹp', 'description' => 'Mô tả hợp lệ']);

    $row = DB::table('core_ai_usage_daily')->where('task_key', 'auto_moderation')->first();
    expect($result['status'])->toBe('moderation_rejected')->and($result['layer'])->toBe(3)
        ->and($row)->not->toBeNull()->and($row->provider)->toBe('openai')->and((int) $row->input_tokens)->toBe(30);
});

it('fails open without calling OpenAI when the AI budget kill switch is on', function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    \Cms\Core\Models\Setting::query()->create([
        'setting_key' => 'core.ai_runtime.budget.kill_switch', 'type' => 'text', 'group_name' => 'core.ai_runtime', 'name' => 'kill_switch',
        'input_setting' => ['title' => 'k', 'description' => 'test', 'isPublic' => true, 'isEnv' => false], 'value' => '1',
    ]);
    OpenAI::fake([]);

    $result = (new AutoModerationService())->moderate(['name' => 'Xe đẹp', 'description' => 'Mô tả hợp lệ']);

    expect($result['status'])->toBe('moderation_approved');
    OpenAI::assertNothingSent();
});
