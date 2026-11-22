<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\Usage\AiCostSpikeDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    DB::table('core_ai_usage_daily')->delete();
    Cache::flush();
    config()->set('core.ai_usage.spike', ['enabled' => true, 'multiplier' => 3.0, 'min_usd' => 2.0, 'baseline_days' => 7]);
    Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function aiSpikeRow(string $date, float $cost, string $task = 'task_a'): void
{
    DB::table('core_ai_usage_daily')->insert([
        'usage_date' => $date, 'month' => substr($date, 0, 7), 'provider' => 'openai', 'model' => 'm',
        'task_key' => $task, 'via' => 'runtime', 'request_count' => 1, 'estimated_cost_usd' => $cost,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function aiSpikeBaseline(float $perDay): void
{
    for ($i = 1; $i <= 7; $i++) {
        aiSpikeRow(Carbon::parse('2026-10-24')->subDays($i)->toDateString(), $perDay);
    }
}

it('alerts once per UTC day when today reaches the multiplier over the 7-day average', function (): void {
    aiSpikeBaseline(1.0);
    aiSpikeRow('2026-10-24', 3.5, 'task_big');
    aiSpikeRow('2026-10-24', 0.5, 'task_small');

    CMSLogger::shouldReceive('warning')->once()->withArgs(
        static fn (array $payload): bool => str_contains($payload['message'], '4.00 USD so far today')
            && str_contains($payload['message'], '1.00 USD/day average')
            && str_contains($payload['message'], 'task_big 3.50 USD'),
    );

    $detector = app(AiCostSpikeDetector::class);
    $first = $detector->alertIfSpiking();
    $second = $detector->alertIfSpiking();

    expect($first['spike'])->toBeTrue()->and($first['alerted'])->toBeTrue()
        ->and($first['threshold_usd'])->toBe(3.0)
        ->and($second['alerted'])->toBeFalse();
});

it('stays quiet below the multiplier and below the absolute floor', function (): void {
    aiSpikeBaseline(1.0);
    aiSpikeRow('2026-10-24', 2.9);
    CMSLogger::shouldReceive('warning')->never();

    expect(app(AiCostSpikeDetector::class)->alertIfSpiking()['spike'])->toBeFalse();

    DB::table('core_ai_usage_daily')->delete();
    aiSpikeBaseline(0.1);
    aiSpikeRow('2026-10-24', 1.5);

    $result = app(AiCostSpikeDetector::class)->alertIfSpiking();
    expect($result['spike'])->toBeFalse()->and($result['threshold_usd'])->toBe(2.0);
});

it('uses the floor when there is no history', function (): void {
    aiSpikeRow('2026-10-24', 2.5);
    CMSLogger::shouldReceive('warning')->once();

    $result = app(AiCostSpikeDetector::class)->alertIfSpiking();

    expect($result['baseline_usd'])->toBe(0.0)->and($result['spike'])->toBeTrue();
});

it('ignores rows outside the baseline window and from today in the average', function (): void {
    aiSpikeRow('2026-10-10', 100.0);
    aiSpikeBaseline(1.0);
    aiSpikeRow('2026-10-24', 10.0);

    expect(app(AiCostSpikeDetector::class)->evaluate()['baseline_usd'])->toBe(1.0);
});

it('can be switched off in settings without a restart', function (): void {
    aiSpikeBaseline(1.0);
    aiSpikeRow('2026-10-24', 50.0);
    $detector = app(AiCostSpikeDetector::class);
    $detector->evaluate();

    Setting::query()->create([
        'setting_key' => 'core.ai_runtime.spike.enabled', 'type' => 'text', 'group_name' => 'core.ai_runtime',
        'name' => 'spike_enabled',
        'input_setting' => ['title' => 'spike_enabled', 'description' => 'test', 'isPublic' => true, 'isEnv' => false],
        'value' => '0',
    ]);
    CMSLogger::shouldReceive('warning')->never();

    $result = $detector->alertIfSpiking();
    expect($result['enabled'])->toBeFalse()->and($result['spike'])->toBeFalse();
    expect(app(AiRuntimeConfigResolver::class)->spike()['enabled'])->toBeFalse();
});

it('prints the evaluation without alerting in dry-run mode', function (): void {
    aiSpikeBaseline(1.0);
    aiSpikeRow('2026-10-24', 5.0);
    CMSLogger::shouldReceive('warning')->never();

    $this->artisan('cms:ai-cost-spike-check', ['--dry-run' => true])
        ->expectsOutputToContain('spike yes, alerted no')
        ->assertSuccessful();
});
