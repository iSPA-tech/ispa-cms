<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Agents\Attributes\AgentTool;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentToolDefinitionData;
use Cms\Core\Agents\Tools\DefaultToolPolicyResolver;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Build a policy resolver with an optional kill switch.
 */
function delegationResolver(bool $killSwitch = false): DefaultToolPolicyResolver
{
    return new DefaultToolPolicyResolver(new ConfigRepository([
        'core.agentic.kill_switch' => $killSwitch,
        'core.agentic.capability_classes.class_0_read_only' => [
            'allowed_side_effect_classes' => ['read_only'],
            'approval_required' => false,
        ],
    ]));
}

/**
 * Build an agent state granting the given tools to the given delegating user.
 *
 * @param array<int, string> $tools
 */
function delegationState(?int $delegatedByUserId, array $tools = ['content.read']): AgentState
{
    return new AgentState(
        runId: 1,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: 0,
        environment: 'testing',
        tenantId: null,
        moduleScope: 'content',
        goal: 'Read posts',
        availableToolDefinitions: $tools,
        profileCapabilityClass: 'class_0_read_only',
        delegatedByUserId: $delegatedByUserId,
    );
}

/**
 * Create a plain user, optionally holding the given "group.name" permission keys.
 *
 * @param array<int, string> $keys
 */
function delegationUser(array $keys = []): int
{
    $userId = (int) DB::table('users')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'Delegator']);

    if ($keys === []) {
        return $userId;
    }

    $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Role ' . Str::random(6)]);
    DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $userId]);

    foreach ($keys as $key) {
        $dot = strrpos($key, '.');
        $permissionId = DB::table(CoreTableConstant::PERMISSIONS)->insertGetId([
            'group' => substr($key, 0, $dot),
            'name' => substr($key, $dot + 1),
            'title' => $key,
        ]);
        DB::table(CoreTableConstant::PERMISSION_ROLE)->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }

    return $userId;
}

beforeEach(function (): void {
    foreach (['role_user', 'permission_role', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', static function (Blueprint $t): void {
        $t->id();
        $t->uuid('uuid')->nullable();
        $t->string('name')->nullable();
        $t->string('email')->nullable();
        $t->string('status')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create(CoreTableConstant::ROLES, static function (Blueprint $t): void {
        $t->id();
        $t->string('name', 100)->nullable();
        $t->text('custom')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create(CoreTableConstant::PERMISSIONS, static function (Blueprint $t): void {
        $t->id();
        $t->string('group')->nullable();
        $t->string('name')->nullable();
        $t->string('title')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create(CoreTableConstant::PERMISSION_ROLE, static function (Blueprint $t): void {
        $t->unsignedBigInteger('role_id');
        $t->unsignedBigInteger('permission_id');
    });
    Schema::create(CoreTableConstant::ROLE_USER, static function (Blueprint $t): void {
        $t->unsignedBigInteger('role_id');
        $t->unsignedBigInteger('user_id');
    });

    // User id 1 is always a webmaster; occupy it so other test users are plain accounts.
    DB::table('users')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => 'Webmaster']);
});

afterEach(function (): void {
    foreach (['role_user', 'permission_role', 'permissions', 'roles', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

test('tool with ability runs for a delegator holding the permission', function () {
    $userId = delegationUser(['content.post.view']);

    $result = delegationResolver()->authorize(
        delegationState($userId),
        new AgentToolDefinitionData(toolName: 'content.read', ability: 'content.post.view'),
    );

    expect($result->allowed)->toBeTrue()
        ->and($result->requiresApproval)->toBeFalse();
});

test('tool with ability runs for a delegator allowed by a gate definition', function () {
    $userId = delegationUser();
    Gate::define('demoDelegatedAbility', static fn (User $user): bool => $user->id === $userId);

    $result = delegationResolver()->authorize(
        delegationState($userId),
        new AgentToolDefinitionData(toolName: 'content.read', ability: 'demoDelegatedAbility'),
    );

    expect($result->allowed)->toBeTrue();
});

test('tool with ability is denied when the run has no delegating user', function () {
    $result = delegationResolver()->authorize(
        delegationState(null),
        new AgentToolDefinitionData(toolName: 'content.read', ability: 'content.post.view'),
    );

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toBe('delegating_user_missing');
});

test('tool with ability is denied when the delegating user row does not exist', function () {
    $result = delegationResolver()->authorize(
        delegationState(999_999),
        new AgentToolDefinitionData(toolName: 'content.read', ability: 'content.post.view'),
    );

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toBe('delegating_user_missing');
});

test('tool with ability is denied when the delegator lacks the ability', function () {
    $userId = delegationUser(['content.post.create']);

    $result = delegationResolver()->authorize(
        delegationState($userId),
        new AgentToolDefinitionData(toolName: 'content.read', ability: 'content.post.view'),
    );

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toBe('delegating_user_lacks_ability');
});

test('tool without ability behaves as before with no delegating user', function () {
    $plain = delegationResolver()->authorize(
        delegationState(null),
        new AgentToolDefinitionData(toolName: 'content.read'),
    );
    $approval = delegationResolver()->authorize(
        delegationState(null),
        new AgentToolDefinitionData(toolName: 'content.read', approvalRequired: true),
    );
    $empty = delegationResolver()->authorize(
        delegationState(null),
        new AgentToolDefinitionData(toolName: 'content.read', ability: ''),
    );

    expect($plain->allowed)->toBeTrue()
        ->and($plain->requiresApproval)->toBeFalse()
        ->and($approval->allowed)->toBeTrue()
        ->and($approval->requiresApproval)->toBeTrue()
        ->and($empty->allowed)->toBeTrue();
});

test('kill switch and allowlist deny before the delegation check', function () {
    $definition = new AgentToolDefinitionData(toolName: 'content.read', ability: 'content.post.view');

    $killed = delegationResolver(killSwitch: true)->authorize(delegationState(null), $definition);
    $noGrants = delegationResolver()->authorize(delegationState(null, []), $definition);
    $notAllowed = delegationResolver()->authorize(delegationState(null, ['content.other']), $definition);

    expect($killed->reason)->toBe('kill_switch_active')
        ->and($noGrants->reason)->toBe('profile_has_no_tool_grants')
        ->and($notAllowed->reason)->toBe('tool_not_in_profile_allowlist');
});

test('webmaster delegator passes through the project gate bypass', function () {
    // User id 1 is the webmaster; Gate::before bypasses webmaster-managed keys such as role.*.
    $result = delegationResolver()->authorize(
        delegationState(1),
        new AgentToolDefinitionData(toolName: 'content.read', ability: 'role.view'),
    );
    $plain = delegationResolver()->authorize(
        delegationState(delegationUser()),
        new AgentToolDefinitionData(toolName: 'content.read', ability: 'role.view'),
    );

    expect($result->allowed)->toBeTrue()
        ->and($plain->reason)->toBe('delegating_user_lacks_ability');
});

test('definition maps ability from the attribute but never persists it', function () {
    $attribute = new AgentTool(family: 'content', ability: 'content.post.view');
    $positional = new AgentTool('name', 'family', 'content', 'core', 'read_only', 'safe', false, true, 'class_0_read_only', 30, 'desc');

    $definition = AgentToolDefinitionData::fromAttribute('content.read', $attribute);

    expect($definition->ability)->toBe('content.post.view')
        ->and($definition->toArray())->not->toHaveKey('ability')
        ->and($positional->approvalRequired)->toBeTrue()
        ->and($positional->description)->toBe('desc')
        ->and($positional->ability)->toBeNull()
        ->and(AgentToolDefinitionData::fromAttribute('x', $positional)->ability)->toBeNull();
});

test('agent state carries the delegating user through budget updates and toArray', function () {
    $state = delegationState(42)->withBudgetRemaining(['steps' => 3]);

    expect($state->delegatedByUserId)->toBe(42)
        ->and($state->toArray()['delegated_by_user_id'])->toBe(42)
        ->and(delegationState(null)->toArray()['delegated_by_user_id'])->toBeNull();
});
