<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
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

/**
 * Create an admin holding the legacy meta permissions.
 *
 * @return User
 */
function legacyMetaGuardAdmin(): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Meta Admin',
        'username' => 'meta-admin-' . Str::lower(Str::random(6)),
        'email' => 'meta-admin-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
    $role = Role::query()->create(['name' => 'Meta guard editor']);
    foreach (['meta_attribute', 'meta_class'] as $group) {
        foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $name) {
            $permission = Permission::query()->create(['group' => $group, 'name' => $name, 'title' => "{$group} {$name}"]);
            $role->permissions()->attach($permission);
        }
    }
    $user->roles()->attach($role);

    return $user;
}

/**
 * Insert a meta attribute row directly (is_custom is not mass assignable).
 *
 * @param bool $custom
 * @return int
 */
function legacyMetaGuardAttribute(bool $custom): int
{
    return DB::table('meta_attributes')->insertGetId([
        'name' => 'guard_' . Str::lower(Str::random(8)),
        'label' => 'Original',
        'type' => 'string',
        'input_type' => 'text',
        'is_custom' => $custom,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a string value row and return its id.
 *
 * @param string $value
 * @return int
 */
function legacyMetaGuardValue(string $value): int
{
    return DB::table('meta_values_string')->insertGetId(['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Link a string value to an attribute through meta_data.
 *
 * @param int $attributeId
 * @param int $valueId
 * @return void
 */
function legacyMetaGuardLink(int $attributeId, int $valueId): void
{
    DB::table('meta_data')->insert([
        'target_type' => 'Guard\\Target',
        'target_id' => $attributeId,
        'meta_attribute_id' => $attributeId,
        'value_type' => \Cms\Core\Models\MetaValueString::class,
        'value_id' => $valueId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('legacy API refuses to update a custom attribute', function () {
    $id = legacyMetaGuardAttribute(true);

    $this->actingAs(legacyMetaGuardAdmin(), 'sanctum')
        ->putJson("/api/v2/admin/meta-attribute/{$id}", ['label' => 'Hacked'])
        ->assertJsonPath('status', 'error');

    expect(DB::table('meta_attributes')->where('id', $id)->value('label'))->toBe('Original');
});

test('legacy API refuses to delete a custom attribute', function () {
    $id = legacyMetaGuardAttribute(true);

    $this->actingAs(legacyMetaGuardAdmin(), 'sanctum')
        ->deleteJson("/api/v2/admin/meta-attribute/{$id}")
        ->assertJsonPath('status', 'error');

    expect(DB::table('meta_attributes')->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue();
});

test('legacy API still updates a non-custom attribute', function () {
    $id = legacyMetaGuardAttribute(false);

    $this->actingAs(legacyMetaGuardAdmin(), 'sanctum')
        ->putJson("/api/v2/admin/meta-attribute/{$id}", ['label' => 'Renamed'])
        ->assertJsonPath('status', 'success');

    expect(DB::table('meta_attributes')->where('id', $id)->value('label'))->toBe('Renamed');
});

test('legacy API refuses to update or delete a class that has a resource key', function () {
    $admin = legacyMetaGuardAdmin();
    $id = DB::table('meta_classes')->insertGetId([
        'name' => 'Original',
        'resource_key' => 'content.post',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v2/admin/meta-class/{$id}", ['name' => 'Hacked'])
        ->assertJsonPath('status', 'error');
    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/v2/admin/meta-class/{$id}")
        ->assertJsonPath('status', 'error');

    $row = DB::table('meta_classes')->where('id', $id)->first();
    expect($row->name)->toBe('Original')->and($row->deleted_at ?? null)->toBeNull();
});

test('attribute index filters by the custom flag', function () {
    $custom = legacyMetaGuardAttribute(true);
    $system = legacyMetaGuardAttribute(false);
    $customName = DB::table('meta_attributes')->where('id', $custom)->value('name');
    $systemName = DB::table('meta_attributes')->where('id', $system)->value('name');
    $admin = legacyMetaGuardAdmin();

    $body = fn (?string $flag): string => $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v2/admin/meta-attribute?perPage=100' . ($flag === null ? '' : "&custom={$flag}"))
        ->assertOk()
        ->getContent();

    expect($body('1'))->toContain($customName)->not->toContain($systemName)
        ->and($body('0'))->toContain($systemName)->not->toContain($customName)
        ->and($body(null))->toContain($customName)->toContain($systemName);
});

test('value list hides values used only by custom attributes', function () {
    $custom = legacyMetaGuardAttribute(true);
    $system = legacyMetaGuardAttribute(false);

    $customOnly = legacyMetaGuardValue('custom-only');
    $shared = legacyMetaGuardValue('shared');
    $loose = legacyMetaGuardValue('no-links');

    legacyMetaGuardLink($custom, $customOnly);
    legacyMetaGuardLink($custom, $shared);
    legacyMetaGuardLink($system, $shared);

    $response = $this->actingAs(legacyMetaGuardAdmin(), 'sanctum')
        ->getJson("/api/v2/admin/meta-attribute/{$system}/options?type=string&per_page=100")
        ->assertOk();

    $text = $response->getContent();

    expect($text)->toContain('shared')->toContain('no-links')->not->toContain('custom-only');
});
