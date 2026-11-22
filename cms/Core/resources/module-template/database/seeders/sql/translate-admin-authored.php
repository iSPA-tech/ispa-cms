<?php

// Every label of the Skeleton module, in English and Vietnamese. Change the wording here, then run
// `php artisan cms:translation-sync Skeleton` and `php artisan cms:translation-generate --public-only`.
// Admin labels: for_adm = 1. Website labels (public pages, mail): for_web = 1.

$entry = static function (
    string $groupName,
    string $name,
    string $en,
    string $vi,
    bool $forWeb = false,
    bool $forAdm = true,
): array {
    return [
        'modules' => ['Skeleton'],
        'group_name' => $groupName,
        'name' => $name,
        'text' => ['en' => $en, 'vi' => $vi],
        'for_web' => $forWeb ? 1 : 0,
        'for_adm' => $forAdm ? 1 : 0,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ];
};

return [
    $entry('nav', 'nav.a.skeleton', 'Skeleton', 'Skeleton'),
    $entry('nav', 'nav.a.skeleton-things', 'Things', 'Things'),
    $entry('text', 'text.skeleton.things.name', 'Name', 'Tên'),
    $entry('text', 'text.skeleton.things.description', 'Description', 'Mô tả'),
    $entry('text', 'text.skeleton.things.status', 'Status', 'Trạng thái'),
    $entry('text', 'text.skeleton.things.status_draft', 'Draft', 'Bản nháp'),
    $entry('text', 'text.skeleton.things.status_published', 'Published', 'Đã đăng'),
    $entry('text', 'text.skeleton.things.publish_now', 'Publish now', 'Đăng ngay'),
    $entry('text', 'text.skeleton.things.updated_at', 'Updated', 'Cập nhật lúc'),
    $entry('text', 'text.skeleton.things.search', 'Search by name', 'Tìm theo tên'),
    $entry('text', 'text.skeleton.things.empty', 'No items yet.', 'Chưa có mục nào.'),
    $entry('text', 'text.skeleton.things.create_title', 'New item', 'Thêm mục mới'),
    $entry('text', 'text.skeleton.things.edit_title', 'Edit item', 'Sửa mục'),
    $entry('text', 'text.skeleton.things.name_required', 'Enter a name.', 'Nhập tên.'),
    $entry('text', 'text.skeleton.things.delete_confirm', 'Delete this item? It moves to the trash.', 'Xoá mục này? Mục sẽ được chuyển vào thùng rác.'),
    $entry('text', 'text.skeleton.things.saved', 'Saved.', 'Đã lưu.'),
    $entry('text', 'text.skeleton.things.deleted', 'Deleted.', 'Đã xoá.'),
    // @mode full
    $entry('text', 'text.skeleton.public.title', 'Skeleton', 'Skeleton', true, false),
    $entry('text', 'text.skeleton.public.empty', 'No published items yet.', 'Chưa có nội dung nào được đăng.', true, false),
    $entry('text', 'text.skeleton.public.back', 'Back to the list', 'Quay lại danh sách', true, false),
    $entry('text', 'text.skeleton.mail.saved_subject', 'Updated: :name', 'Vừa cập nhật: :name', true, false),
    $entry('text', 'text.skeleton.mail.saved_body', ':name was saved.', ':name vừa được lưu.', true, false),
    $entry('text', 'text.skeleton.mail.open', 'Open in the admin', 'Mở trong trang quản trị', true, false),
    // @endmode
];
