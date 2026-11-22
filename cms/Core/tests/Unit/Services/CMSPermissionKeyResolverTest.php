<?php

use Cms\Core\Services\CMSPermissionKeyResolver;

/*
|--------------------------------------------------------------------------
| Permission Key Parsing Tests
|--------------------------------------------------------------------------
*/

test('parse returns correct components for new format (3 parts)', function () {
    $result = CMSPermissionKeyResolver::parse('education.research-period.delete');

    expect($result)->toBe([
        'module' => 'education',
        'group' => 'education.research-period',
        'name' => 'delete',
        'resource' => 'research-period',
    ]);
});

test('parse returns correct components for legacy format (2 parts)', function () {
    $result = CMSPermissionKeyResolver::parse('research-period.delete');

    expect($result)->toBe([
        'module' => null,
        'group' => 'research-period',
        'name' => 'delete',
        'resource' => 'research-period',
    ]);
});

test('parse handles single part key', function () {
    $result = CMSPermissionKeyResolver::parse('admin');

    expect($result)->toBe([
        'module' => null,
        'group' => '',
        'name' => 'admin',
        'resource' => '',
    ]);
});

test('parse handles 4+ parts key (nested modules)', function () {
    $result = CMSPermissionKeyResolver::parse('education.admin.research-period.delete');

    expect($result)->toBe([
        'module' => 'education',
        'group' => 'education.admin.research-period',
        'name' => 'delete',
        'resource' => 'admin.research-period',
    ]);
});

/*
|--------------------------------------------------------------------------
| Candidate Generation Tests
|--------------------------------------------------------------------------
*/

test('getCandidates returns primary and fallback for new format', function () {
    $candidates = CMSPermissionKeyResolver::getCandidates('education.research-period.delete');

    expect($candidates)->toBe([
        'education.research-period.delete',  // Primary (new format)
        'research-period.delete',            // Fallback (legacy format)
    ]);
});

test('getCandidates returns only primary for legacy format', function () {
    $candidates = CMSPermissionKeyResolver::getCandidates('research-period.delete');

    expect($candidates)->toBe([
        'research-period.delete',
    ]);
});

test('getCandidates deduplicates when primary equals legacy', function () {
    // Edge case: single-part group would produce same primary and legacy
    $candidates = CMSPermissionKeyResolver::getCandidates('admin.create');

    expect($candidates)->toBe([
        'admin.create',
    ]);
});

/*
|--------------------------------------------------------------------------
| Permission Check Tests (Main Use Cases)
|--------------------------------------------------------------------------
*/

test('Case A: user with only legacy permission passes new format check', function () {
    $userPermissions = ['research-period.delete']; // Legacy format in DB

    // Checking with new format should PASS due to fallback
    $result = CMSPermissionKeyResolver::hasPermission(
        'education.research-period.delete',
        $userPermissions
    );

    expect($result)->toBeTrue();
});

test('Case B: user with only new permission passes new format check', function () {
    $userPermissions = ['education.research-period.delete']; // New format in DB

    $result = CMSPermissionKeyResolver::hasPermission(
        'education.research-period.delete',
        $userPermissions
    );

    expect($result)->toBeTrue();
});

test('Case C: user with neither permission fails check', function () {
    $userPermissions = ['education.other-resource.delete'];

    $result = CMSPermissionKeyResolver::hasPermission(
        'education.research-period.delete',
        $userPermissions
    );

    expect($result)->toBeFalse();
});

test('Case D: legacy checks still work unchanged', function () {
    $userPermissions = ['research-period.delete'];

    // Checking with legacy format should still work
    $result = CMSPermissionKeyResolver::hasPermission(
        'research-period.delete',
        $userPermissions
    );

    expect($result)->toBeTrue();
});

test('hasPermission returns false for empty permissions array', function () {
    $result = CMSPermissionKeyResolver::hasPermission(
        'education.research-period.delete',
        []
    );

    expect($result)->toBeFalse();
});

test('hasPermission with user having both formats prioritizes match', function () {
    $userPermissions = [
        'education.research-period.delete', // New format
        'research-period.delete',           // Legacy format
    ];

    $result = CMSPermissionKeyResolver::hasPermission(
        'education.research-period.delete',
        $userPermissions
    );

    expect($result)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Conversion Helper Tests
|--------------------------------------------------------------------------
*/

test('toLegacy converts new format to legacy format', function () {
    $result = CMSPermissionKeyResolver::toLegacy('education.research-period.delete');

    expect($result)->toBe('research-period.delete');
});

test('toLegacy returns same key for already legacy format', function () {
    $result = CMSPermissionKeyResolver::toLegacy('research-period.delete');

    expect($result)->toBe('research-period.delete');
});

test('toModulePrefixed adds module prefix to legacy key', function () {
    $result = CMSPermissionKeyResolver::toModulePrefixed('research-period.delete', 'education');

    expect($result)->toBe('education.research-period.delete');
});

test('toModulePrefixed does not double-prefix already prefixed key', function () {
    $result = CMSPermissionKeyResolver::toModulePrefixed('education.research-period.delete', 'education');

    expect($result)->toBe('education.research-period.delete');
});

/*
|--------------------------------------------------------------------------
| Real-World Scenario Tests
|--------------------------------------------------------------------------
*/

test('multiple module permissions work independently', function () {
    $userPermissions = [
        'education.research-period.delete',
        'product.inventory.update',
        'content.post.create',
    ];

    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.delete', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('product.inventory.update', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('content.post.create', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.update', $userPermissions))->toBeFalse();
});

test('mixed legacy and new permissions work together', function () {
    $userPermissions = [
        'education.research-period.delete', // New format
        'user.create',                      // Legacy format
        'role.update',                      // Legacy format
    ];

    // New format checks
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.delete', $userPermissions))->toBeTrue();

    // Legacy format checks
    expect(CMSPermissionKeyResolver::hasPermission('user.create', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('role.update', $userPermissions))->toBeTrue();

    // New format with legacy fallback
    expect(CMSPermissionKeyResolver::hasPermission('core.user.create', $userPermissions))->toBeTrue(); // Falls back to user.create
    expect(CMSPermissionKeyResolver::hasPermission('core.role.update', $userPermissions))->toBeTrue(); // Falls back to role.update
});

test('education module policy prefixes resolve correctly', function () {
    // Simulate user with legacy education permissions
    $userPermissions = [
        'research-period.viewAny',
        'research-period.view',
        'research-period.create',
        'research-period.update',
        'research-period.delete',
    ];

    // All new format checks should pass via fallback
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.viewAny', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.view', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.create', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.update', $userPermissions))->toBeTrue();
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.delete', $userPermissions))->toBeTrue();

    // Non-existent ability should fail
    expect(CMSPermissionKeyResolver::hasPermission('education.research-period.forceDelete', $userPermissions))->toBeFalse();
});
