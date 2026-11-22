<?php

declare(strict_types=1);

namespace Cms\Skeleton\Database\Seeders;

use Cms\Core\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Permissions of the Skeleton module, run by `php artisan cms:modules-sync-permissions Skeleton`. Groups match the
 * policy prefixes; give them to a role in the admin (Roles) to let its users manage things.
 */
final class SkeletonPermissionSeeder extends Seeder
{
    /** Policy prefix => abilities. */
    private const GROUPS = [
        'skeleton.skeleton_thing' => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
    ];

    private const TITLES = [
        'viewAny' => 'View all | Xem tất cả',
        'view' => 'View detail | Xem chi tiết',
        'create' => 'Create new | Tạo mới',
        'edit' => 'Get Edit | Cập nhật - Get',
        'update' => 'Post Update | Cập nhật - Post',
        'delete' => 'Delete | Xóa',
    ];

    public function run(): void
    {
        foreach (self::GROUPS as $group => $abilities) {
            foreach ($abilities as $ability) {
                Permission::query()->updateOrCreate(
                    ['group' => $group, 'name' => $ability],
                    ['title' => self::TITLES[$ability]],
                );
            }
        }
    }
}
