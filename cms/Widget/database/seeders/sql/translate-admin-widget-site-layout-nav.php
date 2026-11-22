<?php

/**
 * Admin i18n sidecar for the Widget Site Layout ADMIN UI chrome (base-admin
 * `src/modules/site-layout/*`). Companion to the frozen contract file
 * translate-admin-widget-site-layout.php -- that file holds the 26
 * contract-validated `widget_site_layout.*` domain rows (slot/context labels,
 * canonical action + error strings) and is INTENTIONALLY left untouched.
 *
 * This file holds the keys the Vue admin components actually call through
 * `$t(...)`: the `nav.a.site-layout-*` group (menu entries + every in-panel
 * label/button/message) plus the handful of `text.widget.*` / `mgs.*` keys
 * the widget instance + emergency surfaces reference. These follow the LIVE
 * nav-group precedent set by cms/Journal (`nav.a.journal-*`), so no frontend
 * key needs to change.
 *
 * Discovery: Cms\Core\Services\TranslationModuleSeederImporter globs
 * `database/seeders/sql/translate-admin-*.php` per module, so this file is
 * imported by `cms:translation-sync --all` (the `admin:i18n:hydrate` step)
 * even while the Widget module stays disabled. Rows are resolved by
 * normalized `name`, so ids only need to be locally unique; this file uses
 * the 999600 block (frozen contract file uses 999900-999954, Journal 998xxx).
 *
 * Regenerate the served pack after changing this file:
 *   php artisan cms:translation-sync --all
 *   php artisan cms:translation-generate
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
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-07-19 00:00:00',
        'updated_at' => '2026-07-19 00:00:00',
    ];
};

return [
    // -- Navigation + route titles ------------------------------------------
    $entry(999600, 'nav', 'nav.a.site-layout', 'Shared areas', 'Khu vực chung'),
    $entry(999601, 'nav', 'nav.a.site-layout-placements', 'Shared areas', 'Khu vực chung'),
    $entry(999602, 'nav', 'nav.a.site-layout-slots', 'Slots', 'Khu vực'),
    $entry(999603, 'nav', 'nav.a.site-layout-widgets', 'Widgets', 'Widget'),
    $entry(999604, 'nav', 'nav.a.site-layout-templates', 'Templates', 'Giao diện'),

    // -- Canvas / catalog / slot chrome -------------------------------------
    $entry(999610, 'nav', 'nav.a.site-layout-add-to-slot', 'Add to slot', 'Thêm vào khu vực'),
    $entry(999611, 'nav', 'nav.a.site-layout-move-to-slot', 'Move to slot', 'Chuyển tới khu vực'),
    $entry(999612, 'nav', 'nav.a.site-layout-empty-slot', 'Empty slot', 'Khu vực trống'),
    $entry(999613, 'nav', 'nav.a.site-layout-placement-deleted', 'Placement deleted', 'Đã xóa vị trí'),
    $entry(999614, 'nav', 'nav.a.site-layout-no-widgets-found', 'No widgets found', 'Không tìm thấy widget'),
    $entry(999615, 'nav', 'nav.a.site-layout-no-targets-found', 'No matching result found', 'Không tìm thấy kết quả phù hợp'),
    $entry(999616, 'nav', 'nav.a.site-layout-scope', 'Scope', 'Phạm vi'),
    $entry(999617, 'nav', 'nav.a.site-layout-template-key', 'Template key', 'Khóa giao diện'),

    // -- Inspector ----------------------------------------------------------
    $entry(999620, 'nav', 'nav.a.site-layout-inspector', 'Inspector', 'Bảng thuộc tính'),
    $entry(999621, 'nav', 'nav.a.site-layout-inspector-empty', 'Select a placement to edit its settings.', 'Chọn một vị trí để chỉnh sửa thiết lập.'),
    $entry(999622, 'nav', 'nav.a.site-layout-definition', 'Definition', 'Định nghĩa'),
    $entry(999623, 'nav', 'nav.a.site-layout-enabled', 'Enabled', 'Đang bật'),
    $entry(999624, 'nav', 'nav.a.site-layout-health', 'Health', 'Tình trạng'),
    $entry(999625, 'nav', 'nav.a.site-layout-target-type', 'Target type', 'Loại đối tượng'),
    $entry(999626, 'nav', 'nav.a.site-layout-manifest-inventory', 'Manifest inventory', 'Danh mục bản kê khai'),

    // -- Context toolbar ----------------------------------------------------
    $entry(999630, 'nav', 'nav.a.site-layout-target-context', 'Target', 'Đối tượng'),
    $entry(999631, 'nav', 'nav.a.site-layout-template-context', 'Template context', 'Ngữ cảnh giao diện'),
    $entry(999632, 'nav', 'nav.a.site-layout-context-project-scoped', 'Project-scoped', 'Theo dự án'),
    $entry(999633, 'nav', 'nav.a.site-layout-context-project-global', 'Global (all projects)', 'Toàn hệ thống (mọi dự án)'),
    $entry(999634, 'nav', 'nav.a.site-layout-locale', 'Locale', 'Ngôn ngữ'),
    $entry(999635, 'nav', 'nav.a.site-layout-viewport', 'Viewport', 'Khung hiển thị'),
    $entry(999636, 'nav', 'nav.a.site-layout-viewport-desktop', 'Desktop', 'Máy tính'),
    $entry(999637, 'nav', 'nav.a.site-layout-viewport-mobile', 'Mobile', 'Điện thoại'),

    // -- Save bar -----------------------------------------------------------
    $entry(999640, 'nav', 'nav.a.site-layout-save-draft', 'Save draft', 'Lưu bản nháp'),
    $entry(999641, 'nav', 'nav.a.site-layout-save-saving', 'Saving…', 'Đang lưu…'),
    $entry(999642, 'nav', 'nav.a.site-layout-save-saved', 'Saved', 'Đã lưu'),
    $entry(999643, 'nav', 'nav.a.site-layout-save-clean', 'All changes saved', 'Đã lưu mọi thay đổi'),
    $entry(999644, 'nav', 'nav.a.site-layout-save-dirty', 'Unsaved changes', 'Có thay đổi chưa lưu'),
    $entry(999645, 'nav', 'nav.a.site-layout-save-conflict', 'Changed by someone else', 'Có người khác vừa thay đổi'),
    $entry(999646, 'nav', 'nav.a.site-layout-save-conflict-explain', 'Someone else has just changed the shared areas. Load the latest version; an arrangement change of yours that was not saved yet will need to be made again.', 'Khu vực chung vừa được người khác thay đổi. Hãy tải lại bản mới nhất; thay đổi sắp xếp bạn vừa làm mà chưa kịp lưu sẽ cần làm lại.'),

    // -- Save conflict resolution -------------------------------------------
    $entry(999650, 'nav', 'nav.a.site-layout-conflict-local', 'Your version', 'Phiên bản của bạn'),
    $entry(999651, 'nav', 'nav.a.site-layout-conflict-server', 'Server version', 'Phiên bản máy chủ'),
    $entry(999652, 'nav', 'nav.a.site-layout-conflict-server-unavailable', 'Server version unavailable', 'Không có phiên bản máy chủ'),
    $entry(999653, 'nav', 'nav.a.site-layout-conflict-keep-local', 'Keep my changes', 'Giữ thay đổi của tôi'),
    $entry(999654, 'nav', 'nav.a.site-layout-conflict-reload', 'Reload latest', 'Tải lại bản mới nhất'),
    $entry(999655, 'nav', 'nav.a.site-layout-apply-to-draft', 'Apply to draft', 'Áp dụng vào bản nháp'),

    // -- Preview ------------------------------------------------------------
    $entry(999660, 'nav', 'nav.a.site-layout-preview', 'Preview', 'Xem trước'),
    $entry(999661, 'nav', 'nav.a.site-layout-preview-frame-title', 'Layout preview', 'Xem trước bố cục'),
    $entry(999662, 'nav', 'nav.a.site-layout-preview-refresh', 'Refresh preview', 'Làm mới xem trước'),
    $entry(999663, 'nav', 'nav.a.site-layout-preview-select-target', 'Select a target to preview.', 'Chọn đối tượng để xem trước.'),
    $entry(999664, 'nav', 'nav.a.site-layout-preview-stale', 'A change is being saved; the preview updates in a moment.', 'Đang lưu thay đổi, bản xem trước sẽ cập nhật ngay.'),
    $entry(999665, 'nav', 'nav.a.site-layout-preview-unavailable', 'The preview could not be shown.', 'Chưa hiển thị được bản xem trước.'),
    $entry(999666, 'nav', 'nav.a.site-layout-preview-mvp-note', 'Preview reflects the current draft.', 'Bản xem trước phản ánh bản nháp hiện tại.'),

    // -- Release / publish --------------------------------------------------
    $entry(999670, 'nav', 'nav.a.site-layout-publish', 'Publish', 'Xuất bản'),
    $entry(999671, 'nav', 'nav.a.site-layout-publish-current', 'Currently published', 'Đang xuất bản'),
    $entry(999672, 'nav', 'nav.a.site-layout-publish-confirm-summary', 'Publish this draft?', 'Xuất bản bản nháp này?'),
    $entry(999673, 'nav', 'nav.a.site-layout-publish-stale', 'The draft changed since you loaded it. Reload before publishing.', 'Bản nháp đã thay đổi kể từ khi bạn tải. Hãy tải lại trước khi xuất bản.'),
    $entry(999674, 'nav', 'nav.a.site-layout-publish-unsaved-note', 'Save your changes before publishing.', 'Lưu thay đổi trước khi xuất bản.'),
    $entry(999675, 'nav', 'nav.a.site-layout-release-history', 'Publish history', 'Lịch sử xuất bản'),
    $entry(999676, 'nav', 'nav.a.site-layout-release-history-empty', 'Nothing has been published yet.', 'Chưa xuất bản lần nào.'),

    // -- Rollback -----------------------------------------------------------
    $entry(999680, 'nav', 'nav.a.site-layout-rollback-title', 'Restore an earlier version', 'Khôi phục một bản trước'),
    $entry(999681, 'nav', 'nav.a.site-layout-rollback-scope-note', 'The site goes back to the version you choose. Everything published after it stays in the history.', 'Website sẽ quay về bản bạn chọn. Những lần xuất bản sau đó vẫn còn trong lịch sử.'),
    $entry(999682, 'nav', 'nav.a.site-layout-rollback-source-label', 'Version to restore', 'Bản muốn khôi phục'),
    $entry(999683, 'nav', 'nav.a.site-layout-rollback-to-version', 'Restore', 'Khôi phục'),
    $entry(999684, 'nav', 'nav.a.site-layout-rollback-of', 'Rollback of', 'Khôi phục từ'),
    $entry(999685, 'nav', 'nav.a.site-layout-rollback-confirm-summary', 'Restore the version published', 'Khôi phục bản xuất bản lúc'),
    $entry(999686, 'nav', 'nav.a.site-layout-rollback-stale', 'The release list changed since you loaded it. Reload and try again.', 'Danh sách phát hành đã thay đổi kể từ khi bạn tải. Hãy tải lại và thử lại.'),
    $entry(999687, 'nav', 'nav.a.site-layout-rollback-denied', 'You do not have permission to roll back.', 'Bạn không có quyền khôi phục.'),

    // -- Emergency controls -------------------------------------------------
    $entry(999690, 'nav', 'nav.a.site-layout-emergency-block', 'Emergency block', 'Chặn khẩn cấp'),
    $entry(999691, 'nav', 'nav.a.site-layout-emergency-clear', 'Clear block', 'Bỏ chặn'),
    $entry(999692, 'nav', 'nav.a.site-layout-archive', 'Archive', 'Lưu trữ'),

    // -- Widget instance health / eligibility (text group) ------------------
    // health_healthy / health_attention are the per-row status badge; the five
    // health_<section> keys are the Site Layout health dashboard section labels
    // (base-admin slots.vue HEALTH_KEYS, rendered as text.widget.health_${key}).
    $entry(999700, 'text', 'text.widget.health_healthy', 'Healthy', 'Bình thường'),
    $entry(999701, 'text', 'text.widget.health_attention', 'Needs attention', 'Cần chú ý'),
    $entry(999705, 'text', 'text.widget.health_registry_drift', 'Registry drift', 'Lệch registry'),
    $entry(999706, 'text', 'text.widget.health_provider_usage', 'Provider usage', 'Sử dụng provider'),
    $entry(999707, 'text', 'text.widget.health_materialization', 'Materialization', 'Khởi tạo bản kê khai'),
    $entry(999708, 'text', 'text.widget.health_blob_manifest_integrity', 'Blob / manifest integrity', 'Toàn vẹn blob và bản kê khai'),
    $entry(999709, 'text', 'text.widget.health_emergency_block', 'Emergency block', 'Chặn khẩn cấp'),
    $entry(999702, 'text', 'text.widget.instance_eligible', 'Eligible', 'Đủ điều kiện'),
    $entry(999703, 'text', 'text.widget.instance_not_eligible', 'Not eligible', 'Không đủ điều kiện'),
    $entry(999704, 'text', 'text.widget.usage_impact', 'Usage impact', 'Mức độ ảnh hưởng'),

    // -- Confirm / error messages (mgs group) -------------------------------
    $entry(999710, 'mgs', 'mgs.confirm.widget_definition_switch', 'Switching the definition resets this instance\'s values. Continue?', 'Đổi định nghĩa sẽ đặt lại giá trị của thực thể này. Tiếp tục?'),
    $entry(999711, 'mgs', 'mgs.confirm.widget_instance_in_use', 'This widget instance is currently in use. Continue?', 'Thực thể widget này đang được sử dụng. Tiếp tục?'),
    $entry(999712, 'mgs', 'mgs.error.widget_runtime_control_topology_unavailable', 'Emergency controls are unavailable in this environment.', 'Điều khiển khẩn cấp không khả dụng trong môi trường này.'),
];
