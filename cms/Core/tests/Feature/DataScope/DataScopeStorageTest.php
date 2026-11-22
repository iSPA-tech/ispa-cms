<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\DataScope\DataScopeAssignment;
use Cms\Core\Models\DataScope\DataScopeGrant;
use Cms\Core\Models\Role;
use Cms\Core\Services\DataScope\Expansion;
use Cms\Core\Tests\Support\DataScopeTestTables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(\Cms\Core\Tests\TestCase::class, DataScopeTestTables::class);

beforeEach(function (): void {
    Schema::dropIfExists('data_scope_assignments');
    Schema::dropIfExists('data_scope_grants');
    Schema::dropIfExists('users');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('personal_access_tokens');
    Schema::create('roles', function (Blueprint $t): void {
        $t->id();
        $t->string('name')->nullable();
        $t->softDeletes();
        $t->timestamps();
    });
    Schema::create('users', function (Blueprint $t): void {
        $t->id();
        $t->string('name')->nullable();
        $t->softDeletes();
        $t->timestamps();
    });
    // Deleting a user revokes its API tokens (P10), so the Sanctum table must exist.
    Schema::create('personal_access_tokens', function (Blueprint $t): void {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name')->nullable();
        $t->string('token', 64)->nullable();
        $t->text('abilities')->nullable();
        $t->string('audience')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });
    // The token revocation writes an audit event; the audit table is not part of this test.
    Event::fake([AuditLogEvent::class]);
    $this->createDataScopeTables();

    $this->makeAssignment = fn (string $type, int $subject, int $node = 5) => DataScopeAssignment::create([
        'subject_type' => $type, 'subject_id' => $subject, 'dimension' => 'org', 'node_id' => $node,
        'expansion' => Expansion::Descendants,
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('data_scope_assignments');
    Schema::dropIfExists('data_scope_grants');
    Schema::dropIfExists('users');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('personal_access_tokens');
});

it('enforces both unique constraints', function (): void {
    DB::table('roles')->insert(['id' => 1, 'name' => 'r']);
    DataScopeGrant::create(['role_id' => 1, 'permission_key' => 'a.b', 'level' => 'all']);
    expect(fn () => DataScopeGrant::create(['role_id' => 1, 'permission_key' => 'a.b', 'level' => 'own']))
        ->toThrow(QueryException::class);

    ($this->makeAssignment)('user', 1);
    expect(fn () => ($this->makeAssignment)('user', 1))->toThrow(QueryException::class);
    expect(($this->makeAssignment)('role', 1)->fresh()->expansion)->toBe(Expansion::Descendants)
        ->and(DataScopeAssignment::first()->uuid)->not->toBeEmpty();
});

it('deletes a role with its grants and assignments but keeps others', function (): void {
    DB::table('roles')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
    DataScopeGrant::create(['role_id' => 1, 'permission_key' => 'a.b', 'level' => 'all']);
    DataScopeGrant::create(['role_id' => 2, 'permission_key' => 'a.b', 'level' => 'all']);
    ($this->makeAssignment)('role', 1);
    ($this->makeAssignment)('role', 2);
    ($this->makeAssignment)('user', 1);

    Role::findOrFail(1)->delete();

    expect(DataScopeGrant::pluck('role_id')->all())->toBe([2])
        ->and(DataScopeAssignment::where('subject_type', 'role')->pluck('subject_id')->all())->toBe([2])
        ->and(DataScopeAssignment::where('subject_type', 'user')->count())->toBe(1);
});

it('deletes a user with its assignments but keeps others', function (): void {
    DB::table('users')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
    ($this->makeAssignment)('user', 1);
    ($this->makeAssignment)('user', 2);
    ($this->makeAssignment)('role', 1);

    User::findOrFail(1)->delete();

    expect(DataScopeAssignment::where('subject_type', 'user')->pluck('subject_id')->all())->toBe([2])
        ->and(DataScopeAssignment::where('subject_type', 'role')->count())->toBe(1);
});
