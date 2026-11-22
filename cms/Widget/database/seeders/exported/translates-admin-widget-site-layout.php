<?php

/**
 * Admin i18n authored sidecar for the Widget Site Layout module (Chunk 1 Task 12).
 *
 * Row shape mirrors Cms\Core\Services\TranslationModuleSeederImporter::importRecords()
 * (see cms/Core/database/seeders/exported/translates.php and any
 * cms/<Module>/database/seeders/sql/translate-admin-*.php sidecar for the
 * live precedent). Every key is EN+VI complete; Slot definitions store only
 * a label_key reference into this file (architecture.md "Slot and Scope
 * Contract"), never a hard-coded single-locale label.
 *
 * KNOWN CONVENTION GAP (disclosed at authoring time in Task 12; CLOSED in
 * Chunk 2 Task 14): the frozen plan path for this file
 * (line
 * 662) places it under cms/Widget/database/seeders/exported/, matching the
 * filename shape of Cms\Core\Database\Seeders\TranslateTableSeed's own
 * sidecar glob (`__DIR__.'/exported/translates-admin-*.php'`). That glob is
 * scoped to cms/Core/database/seeders/exported/ only (see
 * TranslateTableSeed::run(), cms/Core/database/seeders/TranslateTableSeed.php:36),
 * so it will never discover this file here. The actually-live per-module
 * pipeline (`cms:translation-sync --all`, invoked by the
 * `admin:i18n:hydrate` script) instead reads
 * cms/<Module>/database/seeders/sql/translate.php plus
 * sql/translate-admin-*.php (see
 * Cms\Core\Services\TranslationModuleSeederImporter::importFromModule()).
 *
 * TASK 14 RESOLUTION: a live copy of these exact same 26 rows now exists at
 * cms/Widget/database/seeders/sql/translate-admin-widget-site-layout.php
 * (plus a sibling sql/translate.php, required by importFromFile() before it
 * will even scan the sidecar glob), so `cms:translation-sync --all` /
 * `admin:i18n:hydrate` actually imports these keys today. THIS file at the
 * frozen exported/ path is deliberately left untouched (content/shape
 * unchanged) because cms/Widget/tests/Unit/Http/WidgetSiteLayoutContractDefinitionTest.php
 * requires it verbatim via `require` as the Task 12 contract-validated
 * source of truth -- it is not the live-imported copy, and is not expected
 * to be. See guidelines/change-logs.md (Task 14 entry) for the full
 * disclosure.
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
