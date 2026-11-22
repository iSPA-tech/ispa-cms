<?php

use App\Models\User;
use Cms\Core\Models\Language;
use Cms\Core\Models\UserSetting;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\UserAccountSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function makeUserAccountSettingsUser(array $overrides = []): User
{
    return User::query()->forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Settings User',
        'username' => 'settings-user-' . Str::lower(Str::random(6)),
        'email' => 'settings-user-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
    ], $overrides));
}

function seedUserAccountSettingsLanguages(): void
{
    Language::query()->create([
        'name' => 'English',
        'path' => 'en',
        'code' => 'en',
        'status' => 'published',
    ]);
    Language::query()->create([
        'name' => 'Vietnamese',
        'path' => 'vi',
        'code' => 'vi',
        'status' => 'published',
    ]);
    Language::query()->create([
        'name' => 'Draft',
        'path' => 'fr',
        'code' => 'fr',
        'status' => 'draft',
    ]);
}

test('returns canonical defaults and published options', function () {
    $user = makeUserAccountSettingsUser();
    seedUserAccountSettingsLanguages();

    $settings = app(UserAccountSettingsService::class)->forUser($user);

    expect($settings['security']['mfa_mode']['value'])->toBe('off')
        ->and($settings['security']['mfa_mode']['options'])->toHaveCount(4)
        ->and($settings['localization']['language']['value'])->toBe('en')
        ->and($settings['localization']['language']['options'])->toHaveCount(2)
        ->and($settings['localization']['timezone']['options'])->toContain('Asia/Ho_Chi_Minh')
        ->and($settings['security']['two_fa']['value'])->toBeFalse();
});

test('overlays stored settings and projects legacy two factor state', function () {
    $user = makeUserAccountSettingsUser();
    seedUserAccountSettingsLanguages();

    $user->settings()->createMany([
        [
            'setting_group' => 'localization',
            'setting_key' => 'language',
            'setting_value' => ['value' => 'vi', 'type' => 'select'],
        ],
        [
            'setting_group' => 'security',
            'setting_key' => 'email_change_password',
            'setting_value' => ['value' => true, 'type' => 'boolean'],
        ],
        [
            'setting_group' => 'security',
            'setting_key' => 'two_fa',
            'setting_value' => ['value' => true, 'type' => 'boolean'],
        ],
    ]);

    $settings = app(UserAccountSettingsService::class)->forUser($user->fresh());

    expect($settings['localization']['language']['value'])->toBe('vi')
        ->and($settings['security']['email_change_password']['value'])->toBeTrue()
        ->and($settings['security']['mfa_mode']['value'])->toBe('email_only')
        ->and($settings['security']['two_fa']['value'])->toBeTrue();
});

test('updates only canonical settings and never persists options', function () {
    $user = makeUserAccountSettingsUser();
    seedUserAccountSettingsLanguages();
    $unrelated = $user->settings()->create([
        'setting_group' => 'custom',
        'setting_key' => 'keep_me',
        'setting_value' => ['value' => 'unchanged', 'type' => 'text'],
    ]);

    app(UserAccountSettingsService::class)->update($user, [
        'localization' => [
            'language' => [
                'value' => 'vi',
                'type' => 'select',
                'options' => [['name' => 'Injected', 'path' => 'xx']],
            ],
            'timezone' => [
                'value' => 'UTC',
                'type' => 'select',
                'options' => ['Injected'],
            ],
        ],
        'security' => [
            'email_change_password' => [
                'value' => true,
                'type' => 'boolean',
                'options' => ['Injected'],
            ],
            'mfa_mode' => [
                'value' => AdminMfaService::MODE_EMAIL_ONLY,
                'type' => 'select',
                'options' => ['Injected'],
            ],
        ],
        'ignored' => ['value' => 'must not persist'],
    ]);

    expect(UserSetting::query()->findOrFail($unrelated->id)->setting_value)->toMatchArray([
        'value' => 'unchanged',
        'type' => 'text',
    ]);

    $language = $user->settings()->where('setting_key', 'language')->sole();
    $mfa = $user->settings()->where('setting_key', 'mfa_mode')->sole();

    expect($language->setting_value)->toBe(['value' => 'vi', 'type' => 'select'])
        ->and($mfa->setting_value)->toBe(['value' => 'email_only', 'type' => 'select']);
});

test('rolls back preference writes when the requested mfa mode is invalid for the account', function () {
    $user = makeUserAccountSettingsUser();
    seedUserAccountSettingsLanguages();

    expect(fn () => app(UserAccountSettingsService::class)->update($user, [
        'localization' => [
            'language' => ['value' => 'vi'],
            'timezone' => ['value' => 'UTC'],
        ],
        'security' => [
            'email_change_password' => ['value' => true],
            'mfa_mode' => ['value' => AdminMfaService::MODE_TOTP_ONLY],
        ],
    ]))->toThrow(RuntimeException::class);

    expect(UserSetting::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('maps legacy two factor input onto the canonical mfa mode', function () {
    $user = makeUserAccountSettingsUser();
    seedUserAccountSettingsLanguages();

    $settings = app(UserAccountSettingsService::class)->update($user, [
        'security' => [
            'two_fa' => ['value' => true, 'type' => 'boolean'],
        ],
    ]);

    expect($settings['security']['mfa_mode']['value'])->toBe(AdminMfaService::MODE_EMAIL_ONLY)
        ->and($settings['security']['two_fa']['value'])->toBeTrue();
});
