<?php

declare(strict_types=1);

use Cms\Core\Models\Setting;
use Cms\Core\Services\CMSSettingRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * 2026-11-28: credentials defined with `isPublic: false` (Stripe, ZaloPay, MoMo, VNPay, logistics tokens) are no longer
 * written to the plain-text `config/setting.php`, so their readers take them from the settings row. CMSSettingRuntime
 * returns nothing while the runtime is off (legacy sites, `CMS_SETTING_RUNTIME_ENABLED=false`); secret() reads the row
 * in both cases.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function crsRuntime(bool $enabled): CMSSettingRuntime
{
    config(['ispa-cms.setting_runtime_enabled' => $enabled, 'database.redis.enable' => false]);
    $runtime = app(CMSSettingRuntime::class);
    $runtime->forget();

    return $runtime;
}

afterEach(function (): void {
    app(CMSSettingRuntime::class)->forget();
});

test('secret() reads a private row with the settings runtime on and off', function (bool $runtimeOn): void {
    Setting::create([
        'setting_key' => 'order.payment.webhook_secret',
        'type' => 'text',
        'group_name' => 'order',
        'name' => 'payment.webhook_secret',
        'input_setting' => ['isEnv' => false, 'isPublic' => false, 'sub_group' => 'stripe'],
        'value' => 'whsec_row',
    ]);
    Setting::create([
        'setting_key' => 'order.logistics.ghn_token',
        'type' => 'text',
        'group_name' => 'order',
        'name' => 'logistics.ghn_token',
        'input_setting' => ['isEnv' => false, 'isPublic' => false, 'sub_group' => 'ghn'],
        'value' => '',
    ]);
    config(['setting.order.payment.webhook_secret' => null]);

    $runtime = crsRuntime($runtimeOn);

    expect($runtime->secret('order.payment.webhook_secret'))->toBe('whsec_row')
        ->and($runtime->secret('order.logistics.ghn_token', 'none'))->toBe('none')
        ->and($runtime->secret('order.payment.missing', 'none'))->toBe('none')
        // Private rows are encrypted at rest; only the model cast decrypts them.
        ->and(DB::table('settings')->where('setting_key', 'order.payment.webhook_secret')->value('value'))->not->toBe('whsec_row');
})->with([
    'runtime on' => true,
    'runtime off' => false,
]);
