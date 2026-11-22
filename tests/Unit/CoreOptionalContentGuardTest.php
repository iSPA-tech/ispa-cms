<?php

uses(\Tests\TestCase::class);

use Cms\Core\Database\Seeders\PermissionTableSeed;

function permissionSeederWithContent(bool $enabled): PermissionTableSeed
{
    return new class($enabled) extends PermissionTableSeed {
        public function __construct(private readonly bool $enabled) {}

        protected function moduleIsEnabled(string $module): bool
        {
            return $module === 'Content' && $this->enabled;
        }

        public function optionalPermissionsForTest(): array
        {
            return $this->optionalModulePermissions();
        }
    };
}

test('permission seeder omits content permissions when content module is unavailable', function () {
    expect(permissionSeederWithContent(false)->optionalPermissionsForTest())
        ->not->toHaveKeys(['contents', 'posts']);
});

test('permission seeder appends content permissions when content module is available', function () {
    expect(permissionSeederWithContent(true)->optionalPermissionsForTest())
        ->toHaveKeys(['contents', 'posts']);
});
