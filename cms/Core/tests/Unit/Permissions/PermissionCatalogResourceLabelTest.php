<?php

declare(strict_types=1);

use Cms\Core\Services\Permissions\PermissionCatalog;

/*
 * the permission picker read "Education › Xem tất cả" for every Education table. A module permission
 * now names what it is about: the `text.permission.resource.<group>` translation, else the resource made readable.
 */

uses(Cms\Core\Tests\TestCase::class);

test('a module permission group is named by its translation, else by its readable resource', function (): void {
    app('translator')->addLines(['text.permission.resource.education.edu_instructor' => 'Giảng viên'], app()->getLocale());

    expect(PermissionCatalog::resourceLabel('education.edu_instructor'))->toBe('Giảng viên')
        ->and(PermissionCatalog::resourceLabel('education.edu_research_result'))->toBe('Research Result')
        ->and(PermissionCatalog::resourceLabel('questionbank.qb_course'))->toBe('Course')
        // A one-part group: the module label already names it.
        ->and(PermissionCatalog::resourceLabel('contact'))->toBe('');
});
