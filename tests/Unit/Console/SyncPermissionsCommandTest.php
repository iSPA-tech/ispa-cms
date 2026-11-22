<?php

uses(\Tests\TestCase::class);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

test('sync permissions command delegates permission seeding to active modules', function () {
    Schema::dropIfExists('permissions');
    Schema::create('permissions', static function (Blueprint $table) {
        $table->id();
        $table->string('group')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $this->artisan('cms:sync-permissions')
        ->expectsOutputToContain('Syncing permissions');
});

test('deployment option preserves the application cache', function () {
    Schema::dropIfExists('permissions');
    Schema::create('permissions', static function (Blueprint $table) {
        $table->id();
        $table->string('group')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Cache::put('deploy-cache-sentinel', 'kept', 60);

    Artisan::call('cms:sync-permissions', ['--skip-cache-clear' => true]);

    expect(Cache::get('deploy-cache-sentinel'))->toBe('kept');
});

test('sync permissions preserves existing permissions instead of truncating', function () {
    Schema::dropIfExists('permission_role');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('permissions');
    Schema::create('permissions', static function (Blueprint $table) {
        $table->id();
        $table->string('group', 50)->nullable();
        $table->string('name', 50)->nullable();
        $table->string('title')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('roles', static function (Blueprint $table) {
        $table->id();
        $table->string('name', 100)->nullable();
        $table->text('custom')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('permission_role', static function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('permission_id');
    });

    $permission = \Cms\Core\Models\Permission::query()->create([
        'group' => 'test_resource',
        'name' => 'viewAny',
        'title' => 'View Any | Xem tất cả',
    ]);
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'Webmaster',
        'custom' => json_encode(['level' => 0]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('permission_role')->insert([
        'role_id' => $roleId,
        'permission_id' => $permission->id,
    ]);

    $before = \Cms\Core\Models\Permission::query()->count();
    expect($before)->toBe(1);

    $this->artisan('cms:sync-permissions')->assertSuccessful();

    // The pre-existing permission must still exist (not truncated)
    $existing = \Cms\Core\Models\Permission::query()
        ->where('group', 'test_resource')
        ->where('name', 'viewAny')
        ->first();

    expect($existing)->not->toBeNull();
    expect($existing->title)->toBe('View Any | Xem tất cả');
    expect(DB::table('permission_role')
        ->where('role_id', $roleId)
        ->where('permission_id', $permission->id)
        ->exists())->toBeTrue();
});
