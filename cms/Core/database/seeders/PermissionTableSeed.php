<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;


use App\Support\Modules\ModuleStatus;
use App\Constants\Tables;
use Illuminate\Database\Seeder;
use Cms\Core\Models\Permission;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Support\Facades\Log;

class PermissionTableSeed extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $permission = [
            CoreTableConstant::AGENT_APPROVAL => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_ARTIFACT => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_MEMORY => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_POLICY => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            // 'answer': use the authenticated content answer API.
            CoreTableConstant::AGENT_PROFILE => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'answer'],
            CoreTableConstant::AGENT_PROFILE_TOOL => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_RUN => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_RUN_STEP => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_SCHEDULE => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_TOOL_BINDING => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_TOOL_CALL => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::AGENT_TOOL_DEFINITION => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            'data_scope' => ['manage'],
            // Custom field definitions: view reads, manage adds/edits/archives/purges.
            'custom_field' => ['view', 'manage'],
            // Starter packs: view reads and previews, apply installs.
            'starter_pack' => ['view', 'apply'],
            // Hub connect from the admin (spec 2026-11-22): connect this site to the iSPA Hub without a setup code.
            'hub' => ['connect'],
            'agentic' => ['viewAny', 'update', 'approve'],
            CoreTableConstant::API_LOGS => ['viewAny', 'view', 'delete'],  //  3
            Tables::AUDIT_LOGS => ['viewAny', 'view'],  //  5
            Tables::BANNERS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],   //  11
            Tables::CALENDARS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'], //  26
            Tables::CATEGORIES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'locked', 'seo'],    //  32
            CoreTableConstant::CATEGORY_TARGET => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::COMMENTS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],  //  38
            Tables::CONTACTS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'forceDelete'],   //  45
            Tables::EMAILS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],    //  69
            CoreTableConstant::EMAIL_LOGS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::EMAIL_TEMPLATES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],   //  75
            CoreTableConstant::ENUMERATE_TYPES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],  //  81
            CoreTableConstant::ENUMERATES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],    //  87
            Tables::FIELDS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],    //  93
            CoreTableConstant::FIELD_ITEMS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::GALLERIES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'forceDelete', 'seo'],  //  100
            Tables::PLACE_ITEMS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'forceDelete'],  //  107
            Tables::LANGUAGES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],  //  113
            Tables::MENUS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],   //  119
            CoreTableConstant::MENU_ITEMS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::META_CLASSES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],   //  119
            Tables::META_ATTRIBUTES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],   //  119
            CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_DATA => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_VALUES_BIG_NUM => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_VALUES_BOOLEAN => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_VALUES_DATE => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_VALUES_RANGE => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_VALUES_STRING => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_VALUES_TEXT => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::META_VALUES_TINY_NUM => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::PASSWORD_RESETS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::PERMISSIONS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],   //  125
            CoreTableConstant::PERMISSION_ROLE => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::PLACES => [
                'viewAny', 'view', 'create', 'edit', 'update', 'delete', 'comment', 'config', 'content', 'dashboard',
                'confirm', 'province',
            ],   //  135
            Tables::PROVINCES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],  //  163
            Tables::ROLES => ['viewAny', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::ROLE_USER => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::SCHEDULER => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::SEOS => ['viewAny'],
            CoreTableConstant::SETTINGS => ['viewAny', 'create', 'edit', 'update', 'delete', 'ads'],
            Tables::SLUGS => ['viewAny'],
            Tables::STORAGES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'seo'],
            Tables::TRANSLATES => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'generate'],
            CoreTableConstant::UPLOAD_TARGET_PIVOT => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::UPLOADS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            Tables::USERS => [
                'viewAny', 'view', 'create', 'edit', 'update', 'delete', 'email', 'mobile', 'password', 'role', 'type',
                'username', 'student', 'signInAs',
            ],
            CoreTableConstant::USERS_META => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::USERS_SETTINGS => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            // 'trust' (Chunk 1 Task 9b) gates adding/changing/clearing/
            // deleting executable legacy content: widgets.js and
            // widget_themes.content/css. widget_inputs stays non-executable
            // and keeps the plain CRUD action set.
            // 'block' (unified widget catalog Task 7): emergency block/unblock of a widget on this site.
            // 'hub' (widget hub distribution Task 5): download and update widgets from the hub.
            Tables::WIDGET => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'trust', 'block', 'hub'],
            Tables::WIDGET_THEME => ['viewAny', 'view', 'create', 'edit', 'update', 'delete', 'trust'],
            Tables::WIDGET_INPUT => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::WORKFLOW => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::WORKFLOW_CONNECTION => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::WORKFLOW_INSTANCE => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::WORKFLOW_INSTANCE_STEP => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::WORKFLOW_PROJECT => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
            CoreTableConstant::WORKFLOW_STEP => ['viewAny', 'view', 'create', 'edit', 'update', 'delete'],
        ];

        $permission = array_merge($permission, $this->optionalModulePermissions());

        $skipped = 0;

        foreach ($permission as $table => $actions) {
            $group = str($table)->singular()->toString();

            foreach ($actions as $action) {
                $existing = Permission::query()
                    ->where('group', $group)
                    ->where('name', $action)
                    ->first();

                if ($existing) {
                    $title = $this->getTitle($group, $action);

                    // Fill only empty titles; never overwrite a title that is already set.
                    if (($existing->title === null || $existing->title === '') && $title !== '') {
                        $existing->title = $title;
                        $existing->save();
                    }

                    $skipped++;
                    continue;
                }

                Permission::query()->create([
                    'group' => $group,
                    'name' => $action,
                    'title' => $this->getTitle($group, $action),
                ]);
            }
        }

        if ($skipped > 0) {
            Log::info("Permission seeder: skipped {$skipped} existing permissions");
        }
    }

    /**
     * Add permission groups for optional modules only when their constants are available.
     *
     * This keeps the Core seeder runnable in trimmed deployments that do not ship every CMS module.
     *
     * @return array<string, array<int, string>>
     */
    protected function optionalModulePermissions(): array
    {
        $permission = [];

        if ($this->moduleIsEnabled('CmsManagement')) {
            $cmsManagementTables = [
                'domains' => 'Cms\\CmsManagement\\Constants\\CmsManagementTableConstant::CMS_DOMAINS',
                'domain_requests' => 'Cms\\CmsManagement\\Constants\\CmsManagementTableConstant::CMS_DOMAIN_REQUESTS',
            ];

            foreach ($cmsManagementTables as $constant) {
                if (defined($constant)) {
                    $permission[constant($constant)] = [
                        'viewAny', 'view', 'create', 'edit', 'update', 'delete',
                    ];
                }
            }
        }

        if ($this->moduleIsEnabled('Content')) {
            $contentTables = [
                'contents' => 'Cms\\Content\\Constants\\ContentTableConstant::CONTENTS',
                'posts' => 'Cms\\Content\\Constants\\ContentTableConstant::POSTS',
                'tags' => 'Cms\\Content\\Constants\\ContentTableConstant::TAGS',
            ];

            if (defined($contentTables['contents'])) {
                $permission[constant($contentTables['contents'])] = [
                    'viewAny', 'view', 'create', 'edit', 'update', 'delete', 'restore', 'confirm',
                ];
            }

            // The admin tag API (Content TagController) checks these from 2026-11-21.
            if (defined($contentTables['tags'])) {
                $permission[constant($contentTables['tags'])] = ['viewAny', 'create', 'update', 'delete'];
            }

            if (defined($contentTables['posts'])) {
                $permission[constant($contentTables['posts'])] = [
                    'viewAny', 'view', 'create', 'edit', 'update', 'delete', 'comment', 'config', 'document', 'seo', 'video',
                    'audio', 'attachments', 'crawl', 'notification', 'admission', 'events', 'page', 'crawled',
                ];
            }
        }

        $ecommerceOrdersTable = 'Cms\\Ecommerce\\Constants\\EcommerceTableConstant::ORDERS';
        if (defined($ecommerceOrdersTable)) {
            $permission[constant($ecommerceOrdersTable)] = [
                'viewAny', 'view', 'create', 'edit', 'update', 'delete', 'confirm', 'refund', 'transport', 'payment',
            ];
        }

        return $permission;
    }

    protected function moduleIsEnabled(string $module): bool
    {
        return ModuleStatus::isEnabled($module);
    }

    /**
     * Resolve the permission title for a group and action.
     *
     * Group-specific titles take precedence; otherwise the action-based title is used.
     *
     * @param string $group Permission group (singular table name).
     * @param string $action Permission action name.
     * @return string The "English | Tiếng Việt" title, or an empty string when unknown.
     */
    protected function getTitle(string $group, string $action): string
    {
        $groupTitles = [
            'data_scope.manage' => 'Manage data scope | Quản lý phạm vi dữ liệu',
            'custom_field.view' => 'View custom fields | Xem trường tùy chỉnh',
            'custom_field.manage' => 'Manage custom fields | Quản lý trường tùy chỉnh',
            'starter_pack.view' => 'View starter packs | Xem gói khởi tạo',
            'starter_pack.apply' => 'Apply starter packs | Áp dụng gói khởi tạo',
            'agent_profile.answer' => 'Use agent answers | Dùng trả lời tự động',
            'widget.block' => 'Emergency block widgets | Chặn khẩn cấp widget',
            'widget.hub' => 'Download and update widgets from the hub (installs iSPA-signed design code) | Tải và cập nhật widget từ kho (cài mã giao diện do iSPA ký)',
            'hub.connect' => 'Connect this website to the iSPA Hub | Kết nối website với iSPA Hub',
        ];

        return $groupTitles["{$group}.{$action}"] ?? $this->getTitleByName($action);
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function getTitleByName(string $name): string
    {
        return match ($name) {
            'admission' => 'Admission | Tuyển sinh',
            'ads' => 'Advertisement | Quảng cáo',
            'attachments' => 'Attach file | Upload tệp đính kèm',
            'confirm' => 'Confirm | Kiểm duyệt',
            'comment' => 'Comment | Bình luận',
            'config' => 'Config | Cấu hình',
            'content' => 'Content | Nội dung',
            'crawl' => 'Crawl data | Crawl dữ liệu',
            'crawled' => 'Crawled data | Dữ liệu đã crawl',
            'create' => 'Create new | Tạo mới',
            'dashboard' => 'Dashboard | Bảng điều khiển',
            'delete' => 'Delete | Xóa',
            'edit' => 'Get Edit | Cập nhật - Get',
            'email' => 'Edit email | Thay đổi email',
            'events' => 'Events | Sự kiện',
            'forceDelete' => 'Force delete | Xóa cứng',
            'generate' => 'Generate file | Tạo file',
            'locked' => 'Locked | Khóa',
            'mobile' => 'Edit mobile number | Thay đổi số điện thoại',
            'notification' => 'Notification | Thông báo',
            'page' => 'Pages | Trang tĩnh',
            'password' => 'Reset password | Reset mật khẩu',
            'payment' => 'Payment | Thanh toán',
            'province' => 'Province | Tỉnh thành',
            'refund' => 'Refund | Hoàn tiền',
            'restore' => 'Restore | Khôi phục',
            'role' => 'Change User Role | Thay đổi Role',
            'signInAs' => 'Sign In As | Đăng nhập giả',
            'seo' => 'SEO',
            'student' => 'Student | Sinh viên',
            'transport' => 'Transport | Vận chuyển',
            'trust' => 'Trust executable content | Tin cậy nội dung thực thi',
            'type' => 'Change User Type | Thay đổi Type',
            'update' => 'Post Update | Cập nhật - Post',
            'username' => 'Edit username | Thay đổi username',
            'view' => 'View detail | Xem chi tiết',
            'viewAny' => 'View All | Xem tất cả',
            default => '',
        };
    }
}
