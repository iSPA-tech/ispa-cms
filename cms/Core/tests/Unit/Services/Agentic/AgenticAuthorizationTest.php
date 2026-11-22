<?php

use Cms\Core\Services\Agentic\AgenticAuthorization;

function buildMockUser(array $options = []): \App\Models\User
{
    $user = Mockery::mock(\App\Models\User::class)->makePartial();

    $user->shouldReceive('isWebmaster')
        ->andReturn($options['isWebmaster'] ?? false);

    if (isset($options['permissions'])) {
        $user->shouldReceive('hasPermissionResolved')
            ->andReturnUsing(fn (string $key) => in_array($key, $options['permissions'], true));
    } else {
        $user->shouldReceive('hasPermissionResolved')->andReturn(false);
    }

    // Ensure tokenCan and can don't interfere
    $user->shouldReceive('tokenCan')->andReturn(false)->byDefault();
    $user->shouldReceive('can')->andReturn(false)->byDefault();

    return $user;
}

// ── allows() ───────────────────────────────────────────────────

test('allows returns false for null user', function () {
    $auth = new AgenticAuthorization();

    expect($auth->allows(null, ['agentic.viewAny']))->toBeFalse();
});

test('allows returns true for webmaster regardless of permissions', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['isWebmaster' => true]);

    expect($auth->allows($user, ['nonexistent.permission']))->toBeTrue();
});

test('allows returns false when user has no matching permissions', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['other.permission']]);

    expect($auth->allows($user, ['agentic.viewAny']))->toBeFalse();
});

test('allows returns true when user has at least one matching permission', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.viewAny']]);

    expect($auth->allows($user, ['agentic.viewAny', 'workflow.viewAny']))->toBeTrue();
});

test('allows skips empty and non-string keys', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.viewAny']]);

    // Should skip empty/non-string but still find the valid one
    expect($auth->allows($user, ['', 'agentic.viewAny']))->toBeTrue();
});

// ── canView() ──────────────────────────────────────────────────

test('canView returns false for null user', function () {
    $auth = new AgenticAuthorization();

    expect($auth->canView(null))->toBeFalse();
});

test('canView returns true for webmaster', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['isWebmaster' => true]);

    expect($auth->canView($user))->toBeTrue();
});

test('canView returns true when user has agentic.viewAny', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.viewAny']]);

    expect($auth->canView($user))->toBeTrue();
});

test('canView returns true when user has workflow.viewAny', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['workflow.viewAny']]);

    expect($auth->canView($user))->toBeTrue();
});

// ── canProvision() ─────────────────────────────────────────────

test('canProvision returns true when user has agentic.update', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.update']]);

    expect($auth->canProvision($user))->toBeTrue();
});

test('canProvision returns false when user only has viewAny', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.viewAny']]);

    expect($auth->canProvision($user))->toBeFalse();
});

// ── canApprove() ───────────────────────────────────────────────

test('canApprove returns true when user has agentic.approve', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.approve']]);

    expect($auth->canApprove($user))->toBeTrue();
});

test('canApprove also returns true when user has agentic.update', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.update']]);

    expect($auth->canApprove($user))->toBeTrue();
});

// ── canOperate() ───────────────────────────────────────────────

test('canOperate returns true when user has agentic.update', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.update']]);

    expect($auth->canOperate($user))->toBeTrue();
});

test('canOperate returns true when user has workflow.update', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['workflow.update']]);

    expect($auth->canOperate($user))->toBeTrue();
});

test('canOperate returns false when user only has viewAny', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.viewAny']]);

    expect($auth->canOperate($user))->toBeFalse();
});

// ── token ability snapshot ─────────────────────────────────────

test('allows refuses a user whose live permission was removed even if the token still carries the ability', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => []]);
    $user->shouldReceive('tokenCan')->andReturn(true);

    expect($auth->allows($user, ['agentic.viewAny']))->toBeFalse()
        ->and($auth->canView($user))->toBeFalse()
        ->and($auth->canProvision($user))->toBeFalse()
        ->and($auth->canApprove($user))->toBeFalse()
        ->and($auth->canOperate($user))->toBeFalse();
});

test('allows accepts a live permission regardless of the token abilities', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['permissions' => ['agentic.viewAny']]);
    $user->shouldReceive('tokenCan')->andReturn(false);

    expect($auth->allows($user, ['agentic.viewAny']))->toBeTrue();
});

test('webmaster still bypasses when the token carries no ability', function () {
    $auth = new AgenticAuthorization();
    $user = buildMockUser(['isWebmaster' => true]);
    $user->shouldReceive('tokenCan')->andReturn(false);

    expect($auth->allows($user, ['agentic.update']))->toBeTrue();
});
