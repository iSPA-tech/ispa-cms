<?php

/**
 * Admin i18n authored sidecar for the Widget site layout admin screens in base-admin (src/modules/site-layout):
 * labels that used to be raw text in the Vue source.
 * every displayed admin string is a translation key with both `en` and `vi` text.
 *
 * Rows carry no `id`. TranslationModuleSeederImporter matches sidecar rows by name and creates a missing row with an
 * auto-increment id, so this file reserves no translate id range and cannot link to an unrelated row by id.
 */

$labels = [
    ['nav', 'nav.a.site-layout-widget-templates', 'Design Templates', 'Mẫu thiết kế'],
    ['text', 'text.widget.announce_added', '{name} added to {slot}.', 'Đã thêm {name} vào {slot}.'],
    ['text', 'text.widget.announce_applied', 'Applied to draft.', 'Đã áp dụng vào bản nháp.'],
    ['text', 'text.widget.announce_conflict', 'Save conflict detected. Choose how to resolve it.', 'Phát hiện xung đột khi lưu. Hãy chọn cách xử lý.'],
    ['text', 'text.widget.announce_deleted', '{name} deleted. Press Restore to undo.', 'Đã xóa {name}. Nhấn Khôi phục để hoàn tác.'],
    ['text', 'text.widget.announce_moved', 'Moved to {slot}.', 'Đã chuyển tới {slot}.'],
    ['text', 'text.widget.announce_published', 'Layout published.', 'Đã xuất bản bố cục.'],
    ['text', 'text.widget.announce_restored', '{name} restored.', 'Đã khôi phục {name}.'],
    ['text', 'text.widget.announce_rolled_back', 'Layout rolled back.', 'Đã khôi phục bố cục.'],
    ['text', 'text.widget.announce_saved', 'Draft saved.', 'Đã lưu bản nháp.'],
    ['text', 'text.widget.api_error', 'Widget Site Layout API error.', 'Lỗi API bố cục trang.'],
    ['text', 'text.widget.available_input', 'Available Input:', 'Biến đầu vào có thể dùng:'],
    ['text', 'text.widget.background', 'Background', 'Nền'],
    ['text', 'text.widget.copy_name', '{name} copy', '{name} (bản sao)'],
    ['text', 'text.widget.input', 'Input', 'Đầu vào'],
    ['text', 'text.widget.network_error', 'Network error contacting the Widget Site Layout API.', 'Lỗi mạng khi kết nối tới API bố cục trang.'],
    ['text', 'text.widget.preview_generated', 'Preview generated.', 'Đã tạo bản xem trước.'],
    ['text', 'text.widget.request_aborted', 'Request aborted.', 'Đã hủy yêu cầu.'],
    ['text', 'text.widget.source_load_failed', 'Cannot load widget source.', 'Không tải được nguồn widget.'],
    ['text', 'text.widget.template_count', 'Template ({count})', 'Template ({count})'],
    ['text', 'text.widget.template_form', 'Template form', 'Biểu mẫu template'],
    ['text', 'text.widget.template_sources', 'Widget template sources', 'Nguồn mẫu widget'],
    ['text', 'text.widget.theme_default', 'Default', 'Mặc định'],
    ['text', 'text.widget.theme_not_found', 'Theme not found on widget.', 'Không tìm thấy mẫu trên widget này.'],
    ['text', 'text.widget.update_failed', 'Widget update failed.', 'Cập nhật widget thất bại.'],
    ['text', 'text.widget.version_label', 'Widget v{version}', 'Widget v{version}'],
];

return array_map(static fn (array $label): array => [
    'modules' => ['Widget'],
    'group_name' => $label[0],
    'name' => $label[1],
    'text' => [
        'en' => $label[2],
        'vi' => $label[3],
    ],
    'for_web' => 0,
    'for_adm' => 1,
    'for_app' => 0,
    'status' => 'status_published',
    'created_at' => '2026-11-02 00:00:00',
    'updated_at' => '2026-11-02 00:00:00',
], $labels);
