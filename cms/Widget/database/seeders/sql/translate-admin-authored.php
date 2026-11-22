<?php

/**
 * Admin i18n authored sidecar for the Widget module: keys written by hand after the frozen Site Layout inventory
 * (translate-admin-widget-site-layout.php stays row-for-row equal to its exported copy) — rewordings of existing keys
 * and the new keys of the "Khu vực chung" editor.
 *
 * TranslationModuleSeederImporter rewords an existing row only from a `translate-admin-*.php` sidecar, so a wording
 * change lands here as well as in translate.php (fresh installs). Rows carry no `id`: the importer matches by name.
 */

$labels = [
    // Unified Widget Catalog Part 2 (2026-11-21): scope options read "Toàn website" / "Theo loại trang" / "Riêng trang này".
    ['widget_site_layout', 'widget_site_layout.scope.target', 'Specific page', 'Riêng trang này'],

    // Unified Widget Catalog Part 2, Task 9 (2026-11-21): the Widget admin is one screen, "Khu vực chung", written for
    // a site owner. Existing keys reworded in plain words (no draft/release/version vocabulary):
    ['nav', 'nav.a.site-layout', 'Shared areas', 'Khu vực chung'],
    ['nav', 'nav.a.site-layout-placements', 'Shared areas', 'Khu vực chung'],
    ['nav', 'nav.a.site-layout-release-history', 'Publish history', 'Lịch sử xuất bản'],
    ['nav', 'nav.a.site-layout-release-history-empty', 'Nothing has been published yet.', 'Chưa xuất bản lần nào.'],
    ['nav', 'nav.a.site-layout-rollback-title', 'Restore an earlier version', 'Khôi phục một bản trước'],
    ['nav', 'nav.a.site-layout-rollback-scope-note', 'The site goes back to the version you choose. Everything published after it stays in the history.', 'Website sẽ quay về bản bạn chọn. Những lần xuất bản sau đó vẫn còn trong lịch sử.'],
    ['nav', 'nav.a.site-layout-rollback-source-label', 'Version to restore', 'Bản muốn khôi phục'],
    ['nav', 'nav.a.site-layout-rollback-to-version', 'Restore', 'Khôi phục'],
    ['nav', 'nav.a.site-layout-rollback-confirm-summary', 'Restore the version published', 'Khôi phục bản xuất bản lúc'],
    ['nav', 'nav.a.site-layout-save-conflict', 'Changed by someone else', 'Có người khác vừa thay đổi'],
    ['nav', 'nav.a.site-layout-save-conflict-explain', 'Someone else has just changed the shared areas. Load the latest version; an arrangement change of yours that was not saved yet will need to be made again.', 'Khu vực chung vừa được người khác thay đổi. Hãy tải lại bản mới nhất; thay đổi sắp xếp bạn vừa làm mà chưa kịp lưu sẽ cần làm lại.'],
    ['nav', 'nav.a.site-layout-preview-unavailable', 'The preview could not be shown.', 'Chưa hiển thị được bản xem trước.'],
    ['nav', 'nav.a.site-layout-preview-stale', 'A change is being saved; the preview updates in a moment.', 'Đang lưu thay đổi, bản xem trước sẽ cập nhật ngay.'],
    ['widget_site_layout', 'widget_site_layout.editor.status_bootstrapping', 'Loading…', 'Đang tải…'],
    ['widget_site_layout', 'widget_site_layout.editor.status_error', 'The last action did not go through.', 'Thao tác vừa rồi chưa thành công.'],
    ['widget_site_layout', 'widget_site_layout.editor.pick_preview_page', 'Choose a page to view', 'Chọn trang để xem'],
    ['widget_site_layout', 'widget_site_layout.editor.preview_page', 'Page being viewed', 'Trang đang xem'],
    ['widget_site_layout', 'widget_site_layout.editor.local_only_note', 'This widget has just been moved or removed; the preview updates in a moment.', 'Widget này vừa được chuyển hoặc gỡ, bản xem trước sẽ cập nhật ngay.'],

    // New keys of the "Khu vực chung" editor (`widget_site_layout.area.*`): area list, the add drawer and its three
    // steps, the widget editor, the status chip, the publish dialog and the publish history.
    ['widget_site_layout', 'widget_site_layout.area.intro', 'Parts of the site that repeat on many pages, such as the header and the footer.', 'Những phần lặp lại trên nhiều trang của website, như đầu trang và chân trang.'],
    ['widget_site_layout', 'widget_site_layout.area.load_failed', 'The shared areas could not be loaded. Please reload the page.', 'Chưa tải được khu vực chung. Bạn tải lại trang nhé.'],
    ['widget_site_layout', 'widget_site_layout.area.empty', 'The current theme has no shared areas.', 'Giao diện hiện tại chưa có khu vực chung.'],
    ['widget_site_layout', 'widget_site_layout.area.areas_list', 'Areas', 'Các khu vực'],
    ['widget_site_layout', 'widget_site_layout.area.add', '+ Add', '+ Thêm'],
    ['widget_site_layout', 'widget_site_layout.area.add_title', 'Add a widget to “{area}”', 'Thêm widget vào “{area}”'],
    ['widget_site_layout', 'widget_site_layout.area.step_pick', 'Choose a template', 'Chọn mẫu'],
    ['widget_site_layout', 'widget_site_layout.area.step_content', 'Content', 'Nội dung'],
    ['widget_site_layout', 'widget_site_layout.area.step_scope', 'Where it shows', 'Hiển thị ở đâu'],
    ['widget_site_layout', 'widget_site_layout.area.place', 'Place in area', 'Đặt vào khu vực'],
    ['widget_site_layout', 'widget_site_layout.area.back', 'Back', 'Quay lại'],
    ['widget_site_layout', 'widget_site_layout.area.next', 'Continue', 'Tiếp tục'],
    ['widget_site_layout', 'widget_site_layout.area.search', 'Search widgets by name', 'Tìm widget theo tên'],
    ['widget_site_layout', 'widget_site_layout.area.no_widgets', 'No widget found.', 'Không tìm thấy widget nào.'],
    ['widget_site_layout', 'widget_site_layout.area.library_failed', 'The widget list could not be loaded.', 'Chưa tải được danh sách widget.'],
    ['widget_site_layout', 'widget_site_layout.area.retry', 'Try again', 'Thử lại'],
    ['widget_site_layout', 'widget_site_layout.area.design', 'Design', 'Kiểu hiển thị'],
    ['widget_site_layout', 'widget_site_layout.area.no_fields', 'This widget has nothing to fill in.', 'Widget này không có nội dung cần nhập.'],
    ['widget_site_layout', 'widget_site_layout.area.scope_global_hint', 'Shows on every page.', 'Hiện trên mọi trang.'],
    ['widget_site_layout', 'widget_site_layout.area.scope_template_hint', 'Shows on every page of one type.', 'Hiện trên mọi trang thuộc một loại.'],
    ['widget_site_layout', 'widget_site_layout.area.scope_target_hint', 'Shows on one page only.', 'Chỉ hiện trên một trang.'],
    ['widget_site_layout', 'widget_site_layout.area.page_type', 'Page type', 'Loại trang'],
    ['widget_site_layout', 'widget_site_layout.area.pick_page_type', 'Choose a page type', 'Chọn loại trang'],
    ['widget_site_layout', 'widget_site_layout.area.page', 'Page', 'Trang'],
    ['widget_site_layout', 'widget_site_layout.area.blocked', 'Blocked', 'Đang bị chặn'],
    ['widget_site_layout', 'widget_site_layout.area.blocked_hint', 'This widget is blocked, so it does not show on the site.', 'Widget này đang bị chặn nên không hiện trên website.'],
    ['widget_site_layout', 'widget_site_layout.area.blocked_error', 'This widget is blocked and cannot be used yet. Choose another widget, or unblock it in the widget library.', 'Widget này đang bị chặn nên chưa dùng được. Hãy chọn widget khác, hoặc bỏ chặn trong Thư viện widget.'],
    ['widget_site_layout', 'widget_site_layout.area.not_placeable_widget', 'This widget has no design that can be used in shared areas on this site yet. Choose another widget below.', 'Widget này chưa có kiểu hiển thị nào dùng được cho khu vực chung trên site này. Bạn hãy chọn widget khác bên dưới.'],
    ['widget_site_layout', 'widget_site_layout.area.not_placeable_design', 'The design you chose cannot be used in shared areas on this site. The design “{design}” is used instead; you can pick another one below.', 'Kiểu hiển thị bạn chọn chưa dùng được cho khu vực chung trên site này. Đang dùng kiểu “{design}”; bạn có thể đổi kiểu khác ở bên dưới.'],
    ['widget_site_layout', 'widget_site_layout.area.stale_reloaded', 'Someone else has just changed the shared areas. The latest version is now loaded; please check it and try again.', 'Khu vực chung vừa được người khác thay đổi. Đã tải lại bản mới nhất, bạn xem lại rồi thao tác lại nhé.'],
    ['widget_site_layout', 'widget_site_layout.area.stale_reloaded_publish', 'Someone else has just changed the shared areas. Load the latest version to see what will be published.', 'Khu vực chung vừa được người khác thay đổi. Hãy tải lại bản mới nhất để xem những gì sẽ được xuất bản.'],
    ['widget_site_layout', 'widget_site_layout.area.reloaded_check', 'The latest version is now loaded. Please check whether your change is there before trying again.', 'Đã tải lại bản mới nhất. Bạn xem thay đổi của mình đã có chưa rồi hãy thao tác lại.'],
    ['nav', 'nav.a.site-layout-no-targets-found', 'No matching result found', 'Không tìm thấy kết quả phù hợp'],
    ['widget_site_layout', 'widget_site_layout.area.add_failed', 'The widget could not be placed. Please try again.', 'Chưa đặt được widget. Bạn thử lại nhé.'],
    ['widget_site_layout', 'widget_site_layout.area.save_failed', 'The change could not be saved. Please try again.', 'Chưa lưu được thay đổi. Bạn thử lại nhé.'],
    ['widget_site_layout', 'widget_site_layout.area.hand_off_pick_area', 'Press “+ Add” on the area where the widget you chose should go.', 'Bấm “+ Thêm” ở khu vực bạn muốn đặt widget vừa chọn.'],
    ['widget_site_layout', 'widget_site_layout.area.empty_area', 'No widget here yet.', 'Chưa có widget nào.'],
    ['widget_site_layout', 'widget_site_layout.area.not_on_preview', 'This area is not on the page being viewed. Choose another page above to see it.', 'Khu vực này không có trên trang đang xem. Chọn trang khác ở phía trên để thấy khu vực này.'],
    ['widget_site_layout', 'widget_site_layout.area.preview_home', 'Home page', 'Trang chủ'],
    ['widget_site_layout', 'widget_site_layout.area.edit_title', 'Edit “{name}”', 'Sửa “{name}”'],
    ['widget_site_layout', 'widget_site_layout.area.widget_unnamed', 'Widget', 'Widget'],
    ['widget_site_layout', 'widget_site_layout.area.save', 'Save', 'Lưu'],
    ['widget_site_layout', 'widget_site_layout.area.duplicate', 'Duplicate', 'Nhân bản'],
    ['widget_site_layout', 'widget_site_layout.area.remove', 'Remove from area', 'Gỡ khỏi khu vực'],
    ['widget_site_layout', 'widget_site_layout.area.move_up', 'Move up', 'Đưa lên trên'],
    ['widget_site_layout', 'widget_site_layout.area.move_down', 'Move down', 'Đưa xuống dưới'],
    ['widget_site_layout', 'widget_site_layout.area.saved', 'Saved. Press Publish to show it on the site.', 'Đã lưu. Bấm Xuất bản để đưa lên website.'],
    ['widget_site_layout', 'widget_site_layout.area.added', 'Placed in “{area}”. Press Publish to show it on the site.', 'Đã đặt vào “{area}”. Bấm Xuất bản để đưa lên website.'],
    ['widget_site_layout', 'widget_site_layout.area.duplicated', 'Duplicated.', 'Đã nhân bản.'],
    ['widget_site_layout', 'widget_site_layout.area.removed', 'Removed from the area.', 'Đã gỡ khỏi khu vực.'],
    ['widget_site_layout', 'widget_site_layout.area.changes_chip', '● {count} unpublished changes', '● {count} thay đổi chưa xuất bản'],
    ['widget_site_layout', 'widget_site_layout.area.no_changes', 'Nothing to publish', 'Chưa có thay đổi'],
    ['widget_site_layout', 'widget_site_layout.area.publish_button', 'Publish…', 'Xuất bản…'],
    ['widget_site_layout', 'widget_site_layout.area.publish_title', 'Publish the changes to the site?', 'Xuất bản thay đổi lên site?'],
    ['widget_site_layout', 'widget_site_layout.area.publish_confirm', 'Publish', 'Xuất bản'],
    ['widget_site_layout', 'widget_site_layout.area.publish_none', 'There is nothing new to publish.', 'Chưa có thay đổi nào để xuất bản.'],
    ['widget_site_layout', 'widget_site_layout.area.publish_changes_failed', 'The list of changes could not be loaded. You can still publish.', 'Chưa tải được danh sách thay đổi. Bạn vẫn có thể xuất bản.'],
    ['widget_site_layout', 'widget_site_layout.area.change_added', 'Add “{widget}” to {area}', 'Thêm “{widget}” vào {area}'],
    ['widget_site_layout', 'widget_site_layout.area.change_removed', 'Remove “{widget}” from {area}', 'Gỡ “{widget}” khỏi {area}'],
    ['widget_site_layout', 'widget_site_layout.area.change_changed', 'Change “{widget}” in {area}', 'Sửa “{widget}” ở {area}'],
    ['widget_site_layout', 'widget_site_layout.area.published', 'Published. The site now shows the changes.', 'Đã xuất bản. Website đã hiển thị thay đổi.'],
    ['widget_site_layout', 'widget_site_layout.area.live', 'Live', 'Đang chạy'],
    ['widget_site_layout', 'widget_site_layout.area.published_by', 'by {name}', 'bởi {name}'],
    ['widget_site_layout', 'widget_site_layout.area.restored_from', 'Restored from an earlier version', 'Khôi phục từ một bản trước'],
    // Task 10 UX gate (2026-11-21): page picker hint, read-only notice.
    ['widget_site_layout', 'widget_site_layout.area.page_search', 'Type a page name to find it', 'Gõ tên trang để tìm'],
    ['widget_site_layout', 'widget_site_layout.area.read_only', 'You can view the shared areas but not change them. Ask an administrator for the edit permission if you need it.', 'Bạn chỉ xem được khu vực chung, chưa có quyền chỉnh sửa. Nếu cần sửa, hãy nhờ quản trị viên cấp quyền.'],
    ['widget_site_layout', 'widget_site_layout.area.no_preview', 'You do not have permission to preview the site here. The list of areas still shows what each area holds.', 'Bạn chưa có quyền xem trước website ở đây. Danh sách các khu vực vẫn cho biết mỗi khu vực đang có gì.'],
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
    'created_at' => '2026-11-21 00:00:00',
    'updated_at' => '2026-11-21 00:00:00',
], $labels);
