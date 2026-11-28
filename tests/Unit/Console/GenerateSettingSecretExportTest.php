<?php

use App\Console\Commands\GenerateSettingCommand;
use Cms\Core\Models\Setting;
use Illuminate\Support\Str;

uses(\Tests\TestCase::class);

/*
 * 2026-11-28 (cms.ispa.io): `cms:setting-initialize` exported every row without an explicit `isPublic: false` into the
 * plain-text `config/setting.php`. The MailHub rows are written at runtime with no input definition, so the Cloudflare
 * tokens, the R2 keys and the worker/gateway secrets landed in that file in clear text on every settings save.
 * Secret rows must stay out of the public export; rows that readers still take from `config('setting.*')` must stay in.
 */

function secretExportRow(string $settingKey, string $type, ?array $inputSetting): Setting
{
    return (new Setting())->forceFill([
        'setting_key' => $settingKey,
        'group_name' => explode('.', $settingKey)[0],
        'name' => substr($settingKey, strrpos($settingKey, '.') + 1),
        'type' => $type,
        'input_setting' => $inputSetting,
    ]);
}

function secretExportIsPrivate(Setting $setting): bool
{
    $method = new ReflectionMethod(GenerateSettingCommand::class, 'isPrivateSetting');

    return $method->invoke(app(GenerateSettingCommand::class), $setting, (array) $setting->input_setting);
}

test('mailhub secret rows written at runtime are never exported as public settings', function (string $settingKey, string $type): void {
    expect(secretExportIsPrivate(secretExportRow($settingKey, $type, null)))->toBeTrue();
})->with([
    'cloudflare api token' => ['mail_hub.cloudflare_api_token', 'password'],
    'cloudflare automation token' => ['mail_hub.cloudflare_automation_token', 'password'],
    'r2 access key id' => ['mail_hub.r2_access_key_id', 'text'],
    'r2 secret access key' => ['mail_hub.r2_secret_access_key', 'password'],
    'worker secret' => ['mail_hub.worker_secret', 'password'],
    'gateway secret' => ['mail_hub.gateway_secret', 'password'],
    'webmail domain secret' => ['webmail.domain_secret', 'text'],
]);

test('a password row is private even when its definition says public', function (): void {
    expect(secretExportIsPrivate(secretExportRow('cms_management.stripe_secret_key', 'password', ['isPublic' => true])))->toBeTrue()
        ->and(secretExportIsPrivate(secretExportRow('cms_management.stripe_secret_key', 'password', ['title' => 'x'])))->toBeTrue();
});

test('an explicit isPublic false stays private', function (): void {
    expect(secretExportIsPrivate(secretExportRow('security.api_tokens', 'text', ['isPublic' => false])))->toBeTrue();
});

test('rows that readers take from config(setting.*) are still exported', function (string $settingKey, string $type, ?array $inputSetting): void {
    expect(secretExportIsPrivate(secretExportRow($settingKey, $type, $inputSetting)))->toBeFalse();
})->with([
    'brand' => ['basic.brand', 'text', ['title' => 'text.brand']],
    // Not credentials, although the name mentions a password or a key.
    'password policy number' => ['cms_management.portal_password_min_length', 'number', null],
    'stripe publishable key' => ['cms_management.stripe_publishable_key', 'text', null],
    'mailhub account id' => ['mail_hub.cloudflare_account_id', 'text', null],
    'mailhub bucket' => ['mail_hub.r2_bucket', 'text', null],
]);

/*
 * The settings seed defines the payment (VNPay, ZaloPay, MoMo, Stripe), logistics, SES and Pusher credentials. Their
 * readers take them from the settings rows (StripeService, StripeWebhookController through CMSSettingRuntime) or from
 * `.env`, so the seed marks them `isPublic: false` and none of them may reach the plain-text `config/setting.php`.
 * ZaloPay's `key1`/`key2` are MAC keys although their names do not look like credentials.
 */
const SECRET_EXPORT_SEEDED_CREDENTIALS = [
    'order.payment.hash_secret',
    'order.payment.key1',
    'order.payment.key2',
    'order.payment.access_key',
    'order.payment.secret_key',
    'order.payment.secret',
    'order.payment.test_secret_key',
    'order.payment.test_webhook_secret',
    'order.payment.webhook_secret',
    'order.logistics.ghn_token',
    'order.logistics.ghtk_token',
    'order.logistics.vtp_token',
    'order.logistics.dhl_token',
    'order.logistics.ups_token',
    'order.logistics.fedex_token',
    'email.AWS_ACCESS_KEY_ID',
    'email.AWS_SECRET_ACCESS_KEY',
    'api.PUSHER_APP_SECRET',
];

/**
 * A seed row as the command loads it from the database: the raw `input_setting` string goes through the model cast.
 *
 * @param array<string, mixed> $row
 */
function secretExportSeedRow(array $row): Setting
{
    return (new Setting())->setRawAttributes([
        'setting_key' => $row['setting_key'],
        'type' => $row['type'],
        'group_name' => $row['group_name'],
        'name' => $row['name'],
        'input_setting' => $row['input_setting'],
    ], true);
}

function secretExportIsWrittenToFile(Setting $setting): bool
{
    $isEnv = (new ReflectionMethod(GenerateSettingCommand::class, 'shouldSynchronizeToEnvironment'))
        ->invoke(app(GenerateSettingCommand::class), (string) $setting->setting_key, (array) $setting->input_setting);

    return !$isEnv && !secretExportIsPrivate($setting);
}

test('no credential row of the settings seed is written to config/setting.php', function (): void {
    $pattern = (new ReflectionClassConstant(GenerateSettingCommand::class, 'CREDENTIAL_NAME_PATTERN'))->getValue();
    $checked = [];
    $written = [];

    foreach (require base_path('cms/Core/database/seeders/exported/settings.php') as $row) {
        $settingKey = (string) $row['setting_key'];
        if (!in_array($settingKey, SECRET_EXPORT_SEEDED_CREDENTIALS, true)
            && preg_match($pattern, Str::afterLast($settingKey, '.')) !== 1) {
            continue;
        }

        $checked[] = $settingKey;
        if (secretExportIsWrittenToFile(secretExportSeedRow($row))) {
            $written[] = $settingKey;
        }
    }

    expect($written)->toBe([])
        ->and(array_values(array_diff(SECRET_EXPORT_SEEDED_CREDENTIALS, $checked)))->toBe([]);
});
