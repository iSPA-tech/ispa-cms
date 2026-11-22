<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Language;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([
        HttpsProtocol::class,
        Localization::class,
        ApiLocalization::class,
        ThrottleRequests::class,
    ]);
});

function makeSettingsAdminApiUser(array $overrides = []): User
{
    return User::query()->forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Settings Admin',
        'username' => 'settings-admin-' . Str::lower(Str::random(6)),
        'email' => 'settings-admin-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ], $overrides));
}

function grantSettingsUpdatePermission(User $user): void
{
    $permission = Permission::query()->create([
        'group' => 'user',
        'name' => 'update',
        'title' => 'Update users',
    ]);
    $role = Role::query()->create(['name' => 'Settings editor']);
    $role->permissions()->attach($permission);
    $user->roles()->attach($role);
}

test('admin user settings update skips null setting groups', function () {
    $user = makeSettingsAdminApiUser();
    grantSettingsUpdatePermission($user);
    Language::query()->create([
        'name' => 'Vietnamese',
        'path' => 'vi',
        'code' => 'vi',
        'status' => 'published',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
            'security' => [],
            'localization' => [
                'language' => [
                    'value' => 'vi',
                    'type' => 'select',
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('status', 'success');

    $language = UserSetting::query()
        ->where('user_id', $user->id)
        ->where('setting_group', 'localization')
        ->where('setting_key', 'language')
        ->sole();

    expect($language->setting_value)->toMatchArray([
        'value' => 'vi',
        'type' => 'select',
    ]);
});

test('admin user settings update requires authentication', function () {
    $user = makeSettingsAdminApiUser();

    $this->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
        'optional_group' => null,
    ])->assertUnauthorized();

    expect(UserSetting::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('admin user settings update rejects authenticated non-admin users', function () {
    $user = makeSettingsAdminApiUser([
        'type' => 3,
        'level' => 99,
    ]);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
            'optional_group' => null,
        ])
        ->assertUnauthorized();

    expect(UserSetting::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('admin user settings read and update require user update permission', function () {
    $user = makeSettingsAdminApiUser();
    Language::query()->create([
        'name' => 'English',
        'path' => 'en',
        'code' => 'en',
        'status' => 'published',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v2/admin/user/{$user->uuid}/settings")
        ->assertStatus(400)
        ->assertJsonPath('message', __('mgs.no.permission'));

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
            'localization' => [
                'language' => ['value' => 'en'],
                'timezone' => ['value' => 'UTC'],
            ],
            'security' => [
                'email_change_password' => ['value' => true],
                'mfa_mode' => ['value' => 'email_only'],
            ],
        ])
        ->assertStatus(400)
        ->assertJsonPath('message', __('mgs.no.permission'));

    expect(UserSetting::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('admin user settings accepts security only and legacy two factor updates', function () {
    $user = makeSettingsAdminApiUser();
    grantSettingsUpdatePermission($user);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
            'security' => [
                'two_fa' => ['value' => true, 'type' => 'boolean'],
            ],
        ])
        ->assertOk();

    expect(data_get(
        UserSetting::query()
            ->where('user_id', $user->id)
            ->where('setting_key', 'mfa_mode')
            ->sole()
            ->setting_value,
        'value',
    ))->toBe('email_only');

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
            'security' => [
                'email_change_password' => ['value' => true],
            ],
        ])
        ->assertOk();
});

test('admin user settings read returns the base-admin settings shape and stored overlay', function () {
    $user = makeSettingsAdminApiUser();
    grantSettingsUpdatePermission($user);

    Language::query()->create([
        'name' => 'Vietnamese',
        'path' => 'vi',
        'code' => 'vi',
        'status' => 'published',
    ]);
    UserSetting::query()->create([
        'user_id' => $user->id,
        'setting_group' => 'localization',
        'setting_key' => 'language',
        'setting_value' => ['value' => 'vi', 'type' => 'select'],
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v2/admin/user/{$user->uuid}/settings")
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.localization.language.value', 'vi')
        ->assertJsonPath('data.localization.language.options.0.path', 'vi')
        ->assertJsonPath('data.security.mfa_mode.value', 'off')
        ->assertJsonPath('data.security.mfa_mode.options.0.value', 'off')
        ->assertJsonPath('data.localization.timezone.value', 'Asia/Ho_Chi_Minh');
});

test('admin user settings update rejects unknown fields', function () {
    $user = makeSettingsAdminApiUser();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
            'ignored' => ['value' => 'must fail'],
            'security' => [
                'unknown' => ['value' => 'must fail'],
            ],
        ])
        ->assertStatus(406);

    expect(UserSetting::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('admin user settings update rejects invalid canonical values', function () {
    $user = makeSettingsAdminApiUser();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v2/admin/user/{$user->uuid}/settings", [
            'localization' => [
                'language' => ['value' => 'not-published', 'type' => 'select'],
                'timezone' => ['value' => 'Not/A_Timezone', 'type' => 'select'],
            ],
            'security' => [
                'email_change_password' => ['value' => 'yes', 'type' => 'boolean'],
                'mfa_mode' => ['value' => 'unsupported', 'type' => 'select'],
            ],
        ])
        ->assertStatus(406);

    expect(UserSetting::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
