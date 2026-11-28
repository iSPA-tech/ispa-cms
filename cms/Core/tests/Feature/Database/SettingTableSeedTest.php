<?php

use Cms\Core\Database\Seeders\SettingTableSeed;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('setting_key')->nullable()->unique();
        $table->string('type')->default('text');
        $table->string('group_name')->nullable();
        $table->string('name')->nullable();
        $table->text('value')->nullable();
        $table->unsignedBigInteger('enumerate_id')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->text('input_setting')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function () {
    Schema::dropIfExists('settings');
});

test('setting table seed uses setting key instead of fixed id', function () {
    DB::table('settings')->insert([
        'id' => 999,
        'setting_key' => 'email.MAIL_MAILER',
        'type' => 'text',
        'group_name' => 'custom_email',
        'name' => 'CUSTOM_MAILER',
        'value' => 'custom-value',
        'input_setting' => '{"title":"old"}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new SettingTableSeed())->run();

    $rows = DB::table('settings')
        ->where('setting_key', 'email.MAIL_MAILER')
        ->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->id)->toBe(999)
        ->and($row->value)->toBe('custom-value')
        ->and($row->group_name)->toBe('custom_email')
        ->and($row->name)->toBe('CUSTOM_MAILER')
        ->and($row->type)->toBe('select')
        ->and($row->input_setting)->not->toBe('{"title":"old"}');

    expect(DB::table('settings')->where('setting_key', 'geoplaces.provider.google_places.api_key')->exists())
        ->toBeTrue();
});

test('setting table seed does not import private environment-bound values', function () {
    (new SettingTableSeed())->run();

    $privateValues = DB::table('settings')
        ->whereIn('setting_key', [
            'email.MAIL_PASSWORD',
            'advanced.VERSION',
            'geoplaces.provider.google_places.api_key',
        ])
        ->pluck('value', 'setting_key');

    expect($privateValues)->toHaveCount(3)
        ->and($privateValues->filter(static fn ($value): bool => $value !== null))->toBeEmpty();
});

test('setting table seed creates empty hcaptcha credentials', function () {
    (new SettingTableSeed())->run();

    $settings = DB::table('settings')
        ->whereIn('setting_key', [
            'api.HCAPTCHA_SITE_KEY',
            'api.HCAPTCHA_SECRET_KEY',
        ])
        ->get()
        ->keyBy('setting_key');

    expect($settings)->toHaveCount(2)
        ->and($settings['api.HCAPTCHA_SITE_KEY']->type)->toBe('text')
        ->and($settings['api.HCAPTCHA_SECRET_KEY']->type)->toBe('password')
        ->and($settings->pluck('value')->filter(static fn ($value): bool => $value !== null))->toBeEmpty();
});

test('setting table seed exposes hcaptcha only through the shared provider selector', function () {
    (new SettingTableSeed())->run();

    $input = DB::table('settings')
        ->where('setting_key', 'advanced.recaptcha_provider')
        ->value('input_setting');
    $options = collect(json_decode((string) $input, true, flags: JSON_THROW_ON_ERROR)['options'] ?? []);

    expect($options->where('value', 'hcaptcha')->pluck('label')->all())->toBe(['hCaptcha']);
});

/*
 * 2026-11-28: the seeded payment, logistics, SES and Pusher credentials are `isPublic: false`, so
 * `cms:setting-initialize` keeps them out of `config/setting.php`. Existing sites receive the flag on deploy, where
 * `cms:modules-sync` runs this seeder: it rewrites the definition (also an old double-encoded one) and keeps the value.
 */
test('setting table seed marks the seeded credentials private on an existing site and keeps their values', function () {
    DB::table('settings')->insert([
        [
            'setting_key' => 'order.payment.webhook_secret',
            'type' => 'text',
            'group_name' => 'order',
            'name' => 'payment.webhook_secret',
            'value' => 'whsec_existing',
            'input_setting' => '{"title":"setting.payment.stripe_webhook_secret","isEnv":false,"sub_group":"stripe"}',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'setting_key' => 'email.AWS_SECRET_ACCESS_KEY',
            'type' => 'text',
            'group_name' => 'email',
            'name' => 'AWS_SECRET_ACCESS_KEY',
            'value' => 'aws-existing',
            'input_setting' => json_encode(json_encode(['title' => 'text.aws_secret_access_key', 'parent_group' => 'ses'])),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    (new SettingTableSeed())->run();

    $rows = DB::table('settings')
        ->whereIn('setting_key', ['order.payment.webhook_secret', 'email.AWS_SECRET_ACCESS_KEY', 'order.payment.key1', 'order.logistics.ghn_token'])
        ->get()
        ->keyBy('setting_key');

    expect($rows)->toHaveCount(4);
    foreach ($rows as $settingKey => $row) {
        $definition = json_decode((string) $row->input_setting, true);
        expect($definition)->toBeArray("{$settingKey} definition is one JSON object")
            ->and($definition['isPublic'] ?? null)->toBe(false, "{$settingKey} is private");
    }
    expect($rows['order.payment.webhook_secret']->value)->toBe('whsec_existing')
        ->and($rows['email.AWS_SECRET_ACCESS_KEY']->value)->toBe('aws-existing');
});
