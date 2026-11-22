<?php

/**
 * Admin i18n authored sidecar for the Widget Site Layout module -- LIVE
 * COPY wired into the actual hydration pipeline (Chunk 2 Task 14, closing
 * the Task 12 open thread).
 *
 * This is the file Cms\Core\Services\TranslationModuleSeederImporter
 * actually discovers: importFromModule('Widget', $modulePath) builds
 * `$modulePath/database/seeders/sql/translate.php` (this directory's
 * sibling translate.php, required to exist first) plus a glob of
 * `$modulePath/database/seeders/sql/translate-admin-*.php`
 * (TranslationModuleSeederImporter::loadAdminSidecarRecords(), matched
 * against this exact filename). `cms:translation-sync --all` (the second
 * step of the `admin:i18n:hydrate` pnpm script) calls importFromModule() for
 * every module regardless of modules_statuses.json enabled state, so this
 * file is discovered and imported even while the Widget module itself
 * stays off (Gate 0 module-boundary contract).
 *
 * Content is identical to the frozen, contract-validated source at
 * cms/Widget/database/seeders/exported/translates-admin-widget-site-layout.php
 * (same 26 ids/names/text/group_name) -- that file remains the Task 12
 * WidgetSiteLayoutContractDefinitionTest source of truth and is
 * INTENTIONALLY left untouched by this task (its own test asserts its exact
 * 26-row shape via `require`, independent of this live-pipeline copy).
 * TranslationModuleSeederImporter::importRecords() resolves rows primarily
 * by normalized `name` (cms/Core/app/Services/TranslationModuleSeederImporter.php:180-206),
 * so importing the same 26 names from two different files across two
 * different seeding tools is safe by construction: whichever runs first
 * creates the row, the other links to it by name instead of duplicating.
 *
 * @return array<int, array<string, mixed>>
 */

$entry = static function (
    int $id,
    string $groupName,
    string $name,
    string $en,
    string $vi,
    array $modules = ['Widget'],
    bool $forWeb = false,
    bool $forAdm = true,
    bool $forApp = false,
    ?string $status = 'status_published',
    string $createdAt = '2026-07-17 00:00:00',
    ?string $updatedAt = null,
): array {
    return [
        // No 'id': the importer finds rows by name, and an id only links an existing row that may hold another key.
        'modules' => $modules,
        'group_name' => $groupName,
        'name' => $name,
        'text' => [
            'en' => $en,
            'vi' => $vi,
        ],
        'for_web' => $forWeb ? 1 : 0,
        'for_adm' => $forAdm ? 1 : 0,
        'for_app' => $forApp ? 1 : 0,
        'status' => $status,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt ?? $createdAt,
    ];
};

return [
    // Six pilot Slots (architecture.md "Pilot hook mapping"; label_key only, MVP fixed set).
    $entry(999900, 'widget_site_layout', 'widget_site_layout.slot.site_header_before', 'Before Header', 'Trước tiêu đề đầu trang'),
    $entry(999901, 'widget_site_layout', 'widget_site_layout.slot.site_header_after', 'After Header', 'Sau tiêu đề đầu trang'),
    $entry(999902, 'widget_site_layout', 'widget_site_layout.slot.page_before_content', 'Before Page Content', 'Trước nội dung trang'),
    $entry(999903, 'widget_site_layout', 'widget_site_layout.slot.page_after_content', 'After Page Content', 'Sau nội dung trang'),
    $entry(999904, 'widget_site_layout', 'widget_site_layout.slot.site_footer_before', 'Before Footer', 'Trước chân trang'),
    $entry(999905, 'widget_site_layout', 'widget_site_layout.slot.site_footer_after', 'After Footer', 'Sau chân trang'),

    // Three Template Contexts (architecture.md "Pilot hook mapping"; article.detail is a registered future context, not a pilot hook).
    $entry(999910, 'widget_site_layout', 'widget_site_layout.template_context.site_shell', 'Site Shell', 'Khung giao diện site'),
    $entry(999911, 'widget_site_layout', 'widget_site_layout.template_context.page_static', 'Static Page', 'Trang tĩnh'),
    $entry(999912, 'widget_site_layout', 'widget_site_layout.template_context.article_detail', 'Article Detail (future)', 'Chi tiết bài viết (dự kiến)'),

    // Navigation.
    $entry(999920, 'widget_site_layout', 'widget_site_layout.nav.site_layout', 'Site Layout', 'Bố cục trang'),

    // Save/preview/publish/rollback and other route-backed actions.
    $entry(999930, 'widget_site_layout', 'widget_site_layout.action.save_draft', 'Save Draft', 'Lưu bản nháp'),
    $entry(999931, 'widget_site_layout', 'widget_site_layout.action.preview', 'Preview', 'Xem trước'),
    $entry(999932, 'widget_site_layout', 'widget_site_layout.action.publish', 'Publish', 'Xuất bản'),
    $entry(999933, 'widget_site_layout', 'widget_site_layout.action.rollback', 'Rollback', 'Khôi phục'),
    $entry(999934, 'widget_site_layout', 'widget_site_layout.action.materialize', 'Materialize', 'Khởi tạo bản kê khai'),
    $entry(999935, 'widget_site_layout', 'widget_site_layout.action.approve', 'Approve', 'Phê duyệt'),
    $entry(999936, 'widget_site_layout', 'widget_site_layout.action.revoke', 'Revoke', 'Thu hồi'),
    $entry(999937, 'widget_site_layout', 'widget_site_layout.action.block', 'Emergency Block', 'Chặn khẩn cấp'),
    $entry(999938, 'widget_site_layout', 'widget_site_layout.action.clear_block', 'Clear Block', 'Bỏ chặn'),
    $entry(999939, 'widget_site_layout', 'widget_site_layout.action.clone', 'Clone', 'Nhân bản'),
    $entry(999940, 'widget_site_layout', 'widget_site_layout.action.archive', 'Archive', 'Lưu trữ'),

    // Stale/in-use/integrity errors and sync-pending status.
    $entry(999950, 'widget_site_layout', 'widget_site_layout.error.stale', 'This item changed since you loaded it. Refresh and try again.', 'Mục này đã thay đổi kể từ khi bạn tải. Hãy làm mới và thử lại.'),
    $entry(999951, 'widget_site_layout', 'widget_site_layout.error.in_use', 'This item is still in use and cannot be removed.', 'Mục này vẫn đang được sử dụng và không thể xóa.'),
    $entry(999952, 'widget_site_layout', 'widget_site_layout.error.integrity', 'A data integrity problem was detected. Contact an administrator with the correlation ID.', 'Đã phát hiện sự cố toàn vẹn dữ liệu. Hãy liên hệ quản trị viên kèm mã tương quan.'),
    $entry(999953, 'widget_site_layout', 'widget_site_layout.error.sync_pending', 'Saved. Synchronization is still in progress.', 'Đã lưu. Quá trình đồng bộ vẫn đang diễn ra.'),
    $entry(999954, 'widget_site_layout', 'widget_site_layout.error.generic', 'Something went wrong. Please try again.', 'Đã xảy ra lỗi. Vui lòng thử lại.'),
];
