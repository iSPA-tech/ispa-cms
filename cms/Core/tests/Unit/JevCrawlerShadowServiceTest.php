<?php

use Cms\Core\Services\Ai\JevCrawlerShadowService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config()->set('services.typesafe.api_key', 'fake-key');
    config()->set('services.typesafe.crawler_mode', 'shadow');
    Http::preventStrayRequests();
});

it('never calls the provider outside shadow or without credentials', function (string $mode, string $key): void {
    config()->set('services.typesafe.crawler_mode', $mode);
    config()->set('services.typesafe.api_key', $key);
    Http::fake();
    expect(app(JevCrawlerShadowService::class)->assess('test.article', [], 'Is this an article?'))
        ->toMatchArray(['status' => 'disabled', 'publication_effect' => 'none']);
    Http::assertNothingSent();
})->with([['off', 'fake-key'], ['active', 'fake-key'], ['shadow', '']]);

it('records bounded typed judgments without publication authority or source text in logs', function (float $probability, string $recommendation): void {
    Log::spy();
    Http::fake(['api.typesafe.ai/*' => Http::response([
        'model' => 'jev-1.13.0', 'answers' => ['supported' => ['type' => 'noul', 'noul' => $probability]],
    ])]);
    $result = app(JevCrawlerShadowService::class)->assess('test.article', ['text' => 'Public source evidence'], 'Is this an article?');
    expect($result)->toMatchArray(['status' => 'assessed', 'recommendation' => $recommendation, 'publication_effect' => 'none']);
    Log::shouldHaveReceived('info')->once()->with('crawler.jev.shadow', array_diff_key($result, ['probability' => true]));
    expect(json_encode($result))->not->toContain('Public source evidence');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request['questions']['supported']['type'] === 'noul'
        && str_contains($request['questions']['supported']['instructions'], 'untrusted data'));
})->with([[.9, 'supported'], [.1, 'review'], [.5, 'uncertain']]);

it('keeps malformed responses and outages unavailable rather than treating them as approval', function (array $body, int $status): void {
    Http::fake(['api.typesafe.ai/*' => Http::response($body, $status)]);
    expect(app(JevCrawlerShadowService::class)->assess('test.article', ['text' => 'Evidence'], 'Is this supported?'))
        ->toMatchArray(['status' => 'unavailable', 'publication_effect' => 'none']);
})->with([
    [[], 200],
    [['model' => 'jev', 'answers' => ['supported' => ['type' => 'noul', 'noul' => '0.9']]], 200],
    [['model' => 'jev', 'answers' => ['supported' => ['type' => 'noul', 'noul' => 1.1]]], 200],
    [[], 503],
]);

it('skips oversized evidence before a paid provider request', function (): void {
    Http::fake();
    expect(app(JevCrawlerShadowService::class)->assess('test.article', ['text' => str_repeat('x', 24001)], 'Is this supported?'))
        ->toMatchArray(['status' => 'skipped', 'reason' => 'input_bound', 'publication_effect' => 'none']);
    Http::assertNothingSent();
});

it('assessWithMode sends in active mode and honours the model override', function (): void {
    config()->set('services.typesafe.crawler_mode', 'off');
    Http::fake(['api.typesafe.ai/*' => Http::response([
        'model' => 'jev-x', 'answers' => ['supported' => ['type' => 'noul', 'noul' => .9]],
    ])]);
    $result = app(JevCrawlerShadowService::class)->assessWithMode('active', 'test.lane', ['a' => 1], 'Q?', 'jev-x');
    expect($result)->toMatchArray(['status' => 'assessed', 'mode' => 'active', 'recommendation' => 'supported', 'publication_effect' => 'none']);
    Http::assertSent(fn ($request) => $request['model'] === 'jev-x');
});

it('assessWithMode is disabled for off, unknown mode or empty key', function (string $mode, string $key): void {
    config()->set('services.typesafe.api_key', $key);
    Http::fake();
    expect(app(JevCrawlerShadowService::class)->assessWithMode($mode, 'test.lane', [], 'Q?'))
        ->toMatchArray(['status' => 'disabled', 'publication_effect' => 'none']);
    Http::assertNothingSent();
})->with([['off', 'fake-key'], ['bogus', 'fake-key'], ['active', '']]);

it('meters the Jev crawler call in the usage ledger under its task key', function (): void {
    foreach (['2026_10_21_100000_create_core_ai_usage_daily_table', '2026_10_21_110000_add_via_to_core_ai_usage_daily_table'] as $__m) {
        (require base_path('cms/Core/database/migrations/' . $__m . '.php'))->up();
    }
    \Illuminate\Support\Facades\DB::table('core_ai_usage_daily')->delete();
    Http::fake(['api.typesafe.ai/*' => Http::response([
        'model' => 'jev-1.13.0', 'answers' => ['supported' => ['type' => 'noul', 'noul' => .9]], 'usage' => ['cost_usd' => 0.002],
    ])]);

    app(JevCrawlerShadowService::class)->assess('test.article', ['text' => 'Evidence'], 'Is this an article?');

    $row = \Illuminate\Support\Facades\DB::table('core_ai_usage_daily')->where('task_key', 'jev_crawler_shadow')->first();
    expect($row)->not->toBeNull()->and($row->provider)->toBe('typesafe')->and($row->via)->toBe('direct')
        ->and((float) $row->estimated_cost_usd)->toBe(0.002);
});

it('keeps the defaults for existing callers: task key jev_crawler_shadow and a 10 second timeout', function (): void {
    foreach (['2026_10_21_100000_create_core_ai_usage_daily_table', '2026_10_21_110000_add_via_to_core_ai_usage_daily_table'] as $__m) {
        (require base_path('cms/Core/database/migrations/' . $__m . '.php'))->up();
    }
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen = $options;

        return Http::response(['model' => 'jev-1.13.0', 'answers' => ['supported' => ['type' => 'noul', 'noul' => .9]]]);
    });

    app(JevCrawlerShadowService::class)->assessWithMode('shadow', 'test.lane', ['a' => 1], 'Q?');

    expect($seen['timeout'] ?? null)->toBe(10)
        ->and(\Illuminate\Support\Facades\DB::table('core_ai_usage_daily')->pluck('task_key')->all())->toBe(['jev_crawler_shadow']);
});

it('honours an optional ledger task key and an optional timeout', function (): void {
    foreach (['2026_10_21_100000_create_core_ai_usage_daily_table', '2026_10_21_110000_add_via_to_core_ai_usage_daily_table'] as $__m) {
        (require base_path('cms/Core/database/migrations/' . $__m . '.php'))->up();
    }
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen = $options;

        return Http::response(['model' => 'jev-1.13.0', 'answers' => ['supported' => ['type' => 'noul', 'noul' => .9]]]);
    });

    $result = app(JevCrawlerShadowService::class)->assessWithMode('active', 'helpdesk.chat_relevance', ['a' => 1], 'Q?', null, 'helpdesk_jev_relevance', 4);

    expect($result)->toMatchArray(['status' => 'assessed', 'mode' => 'active'])
        ->and($seen['timeout'] ?? null)->toBe(4)
        ->and(\Illuminate\Support\Facades\DB::table('core_ai_usage_daily')->pluck('task_key')->all())->toBe(['helpdesk_jev_relevance']);
});

it('answers budget_refused only when asked to, and never sends a request after a refusal', function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    \Cms\Core\Models\Setting::query()->create([
        'setting_key' => 'core.ai_runtime.budget.kill_switch', 'type' => 'text', 'group_name' => 'core.ai_runtime',
        'name' => 'kill_switch', 'value' => '1',
    ]);
    app(\Cms\Core\Services\Ai\AiRuntimeConfigResolver::class)->flushCache();
    Http::fake();
    $service = app(JevCrawlerShadowService::class);

    // Existing callers: a refusal is still "unavailable" (the old behavior).
    expect($service->assessWithMode('shadow', 'test.lane', ['a' => 1], 'Q?'))->toMatchArray(['status' => 'unavailable']);
    // Opt in: a distinct status with the reason.
    expect($service->assessWithMode('active', 'helpdesk.chat_relevance', ['a' => 1], 'Q?', null, 'helpdesk_jev_relevance', 4, true))
        ->toMatchArray(['status' => 'budget_refused', 'reason' => 'kill_switch', 'mode' => 'active', 'publication_effect' => 'none']);
    Http::assertNothingSent();
});
