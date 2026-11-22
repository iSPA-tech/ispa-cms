<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Services\CMSSettingUnified;

beforeEach(function () {
    if (!Schema::hasTable('settings')) {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('type', 20)->default('text');
            $table->string('group_name', 50)->nullable();
            $table->string('name', 50)->nullable();
            $table->longText('value')->nullable();
            $table->unsignedBigInteger('enumerate_id')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->text('input_setting')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    } else {
        DB::table('settings')->truncate();
    }

    config()->set('database.redis.enable', false);
});

afterEach(function () {
    putenv('CLOUDFLARE_TURNSTILE_SITE_KEY');
    putenv('XUNIT_RUNTIME_ENV_SECRET');
});

function createUnifiedSetting(array $overrides = []): Setting
{
    return Setting::create(array_merge([
        'setting_key' => 'xunit.setting_' . random_int(10000, 99999),
        'type' => 'text',
        'group_name' => 'xunit',
        'name' => 'XUNIT_SETTING',
        'value' => null,
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ], $overrides));
}

test('env setting resolves from setting name env variable', function () {
    createUnifiedSetting([
        'setting_key' => 'api.XUNIT_RUNTIME_ENV_SECRET',
        'name' => 'XUNIT_RUNTIME_ENV_SECRET',
        'input_setting' => ['isEnv' => true, 'isPublic' => false],
    ]);

    putenv('XUNIT_RUNTIME_ENV_SECRET=env-site-token');
    config()->set('setting.api.XUNIT_RUNTIME_ENV_SECRET', 'public-config-token');

    $service = app(CMSSettingUnified::class);

    expect($service->get('api.XUNIT_RUNTIME_ENV_SECRET', 'fallback'))->toBe('env-site-token');
});

test('private setting resolves from redis runtime private map', function () {
    createUnifiedSetting([
        'setting_key' => 'api.PRIVATE_RUNTIME_TOKEN',
        'name' => 'PRIVATE_RUNTIME_TOKEN',
        'input_setting' => ['isEnv' => false, 'isPublic' => false],
    ]);

    config()->set('database.redis.enable', true);

    $redis = \Mockery::mock();
    $redis->shouldReceive('get')
        ->once()
        ->with('settings.runtime.private')
        ->andReturn(json_encode([
            'api.PRIVATE_RUNTIME_TOKEN' => 'private-runtime-secret',
        ], JSON_THROW_ON_ERROR));

    Redis::shouldReceive('connection')
        ->once()
        ->andReturn($redis);

    $service = app(CMSSettingUnified::class);

    expect($service->get('api.PRIVATE_RUNTIME_TOKEN', 'fallback'))->toBe('private-runtime-secret');
});

test('public setting resolves from generated config snapshot', function () {
    createUnifiedSetting([
        'setting_key' => 'order.tax.enabled',
        'name' => 'ORDER_TAX_ENABLED',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);

    config()->set('setting.order.tax.enabled', true);

    $service = app(CMSSettingUnified::class);

    expect($service->get('order.tax.enabled', false))->toBeTrue();
});

test('returns default when runtime value is missing and db fallback is disabled', function () {
    createUnifiedSetting([
        'setting_key' => 'xunit.runtime.missing_default',
        'name' => 'XUNIT_RUNTIME_MISSING_DEFAULT',
        'value' => 'db-only-value',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);

    $service = app(CMSSettingUnified::class);

    expect($service->get('xunit.runtime.missing_default', 'fallback-default'))->toBe('fallback-default');
});

test('allow_db_fallback returns database value when runtime layers miss', function () {
    createUnifiedSetting([
        'setting_key' => 'xunit.runtime.db_fallback',
        'name' => 'XUNIT_RUNTIME_DB_FALLBACK',
        'value' => 'db-fallback-value',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);

    $service = app(CMSSettingUnified::class);

    expect($service->get('xunit.runtime.db_fallback', 'fallback-default', ['allow_db_fallback' => true]))
        ->toBe('db-fallback-value');
});

test('iSetting helper delegates to unified resolver', function () {
    createUnifiedSetting([
        'setting_key' => 'xunit.helper.public',
        'name' => 'XUNIT_HELPER_PUBLIC',
        'input_setting' => ['isEnv' => false, 'isPublic' => true],
    ]);

    config()->set('setting.xunit.helper.public', 'helper-value');

    expect(iSetting('xunit.helper.public', 'fallback-default'))->toBe('helper-value');
});
