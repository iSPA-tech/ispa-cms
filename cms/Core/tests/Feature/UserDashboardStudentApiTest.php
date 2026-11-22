<?php

use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
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

    DB::table(CoreTableConstant::ENUMERATES)->updateOrInsert(
        ['enum_key' => UserStatusEnum::USER_ACTIVE->value],
        ['name' => 'Active', 'status' => true],
    );
});

function makeStudentDashboardUser(array $overrides = []): User
{
    return User::query()->forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Student dashboard user',
        'username' => 'student-dashboard-' . Str::lower(Str::random(8)),
        'email' => 'student-dashboard-' . Str::lower(Str::random(8)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => UserType::WEBMASTER,
        'level' => 0,
        'status' => UserStatusEnum::USER_ACTIVE->value,
    ], $overrides));
}

function grantStudentDashboardPermission(User $user): void
{
    $permission = Permission::query()->create([
        'group' => 'user',
        'name' => 'student',
        'title' => 'View student dashboard',
    ]);
    $role = Role::query()->create(['name' => 'Student dashboard viewer']);
    $role->permissions()->attach($permission);
    $user->roles()->attach($role);
}

test('student dashboard returns active and inactive client counts', function () {
    $admin = makeStudentDashboardUser();
    grantStudentDashboardPermission($admin);
    makeStudentDashboardUser(['type' => UserType::CLIENT]);
    makeStudentDashboardUser([
        'type' => UserType::CLIENT,
        'email_verified_at' => null,
    ]);
    makeStudentDashboardUser(['type' => UserType::USER]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v2/admin/user/dashboard/student')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.totalStudent', 2)
        ->assertJsonPath('data.totalStudentActive', 1)
        ->assertJsonPath('data.totalStudentInactive', 1);
});

test('student dashboard requires the student permission', function () {
    $admin = makeStudentDashboardUser();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v2/admin/user/dashboard/student')
        ->assertStatus(400)
        ->assertJsonPath('message', __('mgs.no.permission'));
});
