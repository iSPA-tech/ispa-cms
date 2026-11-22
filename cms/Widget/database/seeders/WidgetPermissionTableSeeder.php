<?php

declare(strict_types=1);

namespace Cms\Widget\Database\Seeders;

use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the frozen Site Layout permission inventory (plan Chunk 2 Task 14;
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * "permissions" section) and attaches every enabled action to
 * Webmaster/super-admin.
 *
 * `widget_template.import` is intentionally NOT seeded here -- it is
 * reserved for Chunk 4 (fixture: {"key": "widget_template.import",
 * "reserved": true, "unlocks": "chunk-4"}). `widget.trust` and
 * `widget_theme.trust` stay Core-owned (Task 9b, cms/Core/database/seeders/PermissionTableSeed.php)
 * and are never created or touched by this seeder.
 *
 * Idempotent: updateOrCreate by (group, name) never duplicates rows, and
 * syncWithoutDetaching on the role pivot never detaches unrelated
 * permissions a role already had. Pattern verbatim from the proven
 * PushNotification precedent:
 * cms/PushNotification/database/seeders/PushNotificationPermissionTableSeeder.php.
 */
final class WidgetPermissionTableSeeder extends Seeder
{
    /**
     * Frozen permission inventory: group => [name => bilingual title].
     *
     * @var array<string, array<string, string>>
     */
    private array $permissions = [
        'widget_instance' => [
            'viewAny' => 'View widget instances | Xem danh sách widget',
            'view' => 'View widget instance detail | Xem chi tiết widget',
            'create' => 'Create widget instance | Tạo widget',
            'update' => 'Update widget instance | Cập nhật widget',
            'delete' => 'Delete widget instance | Xóa widget',
        ],
        'widget_layout' => [
            'viewAny' => 'View site layouts | Xem danh sách bố cục trang',
            'view' => 'View site layout detail | Xem chi tiết bố cục trang',
            'update' => 'Edit site layout draft | Chỉnh sửa bản nháp bố cục trang',
            'preview' => 'Preview site layout | Xem trước bố cục trang',
            'publish' => 'Publish site layout | Xuất bản bố cục trang',
            'rollback' => 'Rollback site layout | Khôi phục bố cục trang',
        ],
        'widget_template' => [
            'approve' => 'Approve widget template manifest | Phê duyệt manifest mẫu widget',
            'emergencyDisable' => 'Emergency-disable widget template | Chặn khẩn cấp mẫu widget',
        ],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = [];

        foreach ($this->permissions as $group => $actions) {
            foreach ($actions as $name => $title) {
                $permission = Permission::query()->updateOrCreate(
                    [
                        'group' => $group,
                        'name' => $name,
                    ],
                    [
                        'title' => $title,
                    ],
                );

                $permissionIds[] = $permission->id;
            }
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        Role::query()
            ->whereIn('name', ['Webmaster', 'super-admin'])
            ->get()
            ->each(
                static fn (Role $role) => $role->permissions()->syncWithoutDetaching($permissionIds),
            );
    }
}
