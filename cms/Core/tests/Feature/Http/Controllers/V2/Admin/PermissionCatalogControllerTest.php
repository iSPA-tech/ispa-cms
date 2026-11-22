<?php

/**
 * Permission catalog API contract (modules summary, paginated catalog, role permission read/sync).
 *
 * The suite grants super-admin by attaching the role through the `roles()` relation.
 * `User::assignRole()` takes a numeric role id (and only attaches an already-attached role), so the
 * former `assignRole('super-admin')` call raised a TypeError before any assertion ran.
 * Requests authenticate with the `sanctum` guard because the admin API group is behind `auth:sanctum`;
 * the default web guard is redirected (302) instead of served. Requests also use HTTPS because the
 * HttpsProtocol middleware redirects plain http outside the local environment. The admin user is a
 * WEBMASTER because the `api.admin` middleware only admits WEBMASTER/ADMIN user types (401 otherwise).
 * The catalog payload is nested (`data.data` rows + `data.meta`, the shape base-admin's usePermissionCatalog
 * reads) and an invalid permission id answers the standard ires() error status 400, not 200.
 */

use Cms\Core\Tests\TestCase;
use Cms\Core\Models\Role;
use Cms\Core\Models\Permission;
use App\Constants\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // HttpsProtocol redirects plain-http requests outside the local environment (302); request HTTPS.
    URL::forceScheme('https');

    // Create admin user manually (no factory)
    $this->admin = User::create([
        'name' => 'Test Admin',
        'email' => 'admin@test.com',
        'password' => Hash::make('password'),
        'type' => UserType::WEBMASTER,
    ]);
    
    // Get or create super-admin role
    $superAdmin = Role::firstOrCreate(
        ['name' => 'super-admin'],
        ['name' => 'super-admin', 'user_id' => $this->admin->id]
    );
    $this->admin->roles()->syncWithoutDetaching([$superAdmin->id]);
    
    // Create test permissions
    Permission::query()->delete();
    
    Permission::create(['name' => 'create', 'group' => 'education.question', 'title' => 'Create Question']);
    Permission::create(['name' => 'update', 'group' => 'education.question', 'title' => 'Update Question']);
    Permission::create(['name' => 'delete', 'group' => 'education.question', 'title' => 'Delete Question']);
    Permission::create(['name' => 'view', 'group' => 'core.user', 'title' => 'View User']);
    Permission::create(['name' => 'create', 'group' => 'core.user', 'title' => 'Create User']);
});

test('modules endpoint returns grouped permission counts', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v2/admin/permissions/modules');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['module', 'count'],
            ],
        ]);

    $data = $response->json('data');
    
    // Should have 2 modules: education and core
    expect($data)->toHaveCount(2);
    
    $education = collect($data)->firstWhere('module', 'education');
    $core = collect($data)->firstWhere('module', 'core');
    
    expect($education['count'])->toBe(3);
    expect($core['count'])->toBe(2);
});

test('catalog endpoint returns paginated permissions for a module', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v2/admin/permissions/catalog?module=education');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'key', 'name', 'group', 'title'],
                ],
                'meta' => ['page', 'per_page', 'total', 'last_page'],
            ],
        ]);

    $data = $response->json('data.data');
    $meta = $response->json('data.meta');
    
    expect($data)->toHaveCount(3);
    expect($meta['total'])->toBe(3);
});

test('catalog endpoint supports search', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v2/admin/permissions/catalog?module=education&search=create');

    $response->assertOk();

    $data = $response->json('data.data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('create');
});

test('getPermissions returns role permission IDs and keys', function () {
    $role = Role::create(['name' => 'test-role', 'user_id' => $this->admin->id]);
    $permissions = Permission::where('group', 'like', 'education%')->get();
    $role->permissions()->attach($permissions->pluck('id'));

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/v2/admin/role/{$role->id}/permissions");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['permission_ids', 'permission_keys'],
        ]);

    $data = $response->json('data');
    expect($data['permission_ids'])->toHaveCount(3);
    expect($data['permission_keys'])->toContain('education.question.create');
});

test('syncPermissions updates role permissions', function () {
    $role = Role::create(['name' => 'test-role', 'user_id' => $this->admin->id]);
    $permissions = Permission::where('group', 'like', 'core%')->get();

    $response = $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v2/admin/role/{$role->id}/permissions", [
            'permission_ids' => $permissions->pluck('id')->all(),
        ]);

    $response->assertOk()
        ->assertJsonPath('data.synced_count', 2);

    // Verify pivot table
    $role->refresh();
    expect($role->permissions)->toHaveCount(2);
});

test('syncPermissions rejects invalid permission IDs', function () {
    $role = Role::create(['name' => 'test-role', 'user_id' => $this->admin->id]);

    $response = $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v2/admin/role/{$role->id}/permissions", [
            'permission_ids' => [99999, 99998],
        ]);

    $response->assertStatus(400)
        ->assertJsonPath('status', 'error');
});
