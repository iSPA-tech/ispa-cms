<?php

/**
 * Names of what each permission group of the Core module is about (`text.permission.resource.<group>`), read by the
 * role permission editor and the workflow permission picker ("Liên hệ › Xem tất cả"). Imported by cms:translation-sync; existing
 * keys keep their wording, only missing keys are added (2026-11-28).
 */
$labels = [
    'text.permission.resource.contact' => ['Contacts', 'Liên hệ'],
    'text.permission.resource.email' => ['Inbox', 'Hộp thư'],
    'text.permission.resource.email_template' => ['E-mail templates', 'Mẫu email'],
    'text.permission.resource.email_log' => ['E-mail log', 'Nhật ký email'],
    'text.permission.resource.user' => ['Users', 'Người dùng'],
    'text.permission.resource.role' => ['Roles', 'Vai trò'],
    'text.permission.resource.permission' => ['Permissions', 'Quyền'],
    'text.permission.resource.category' => ['Categories', 'Danh mục'],
    'text.permission.resource.banner' => ['Banners', 'Banner'],
    'text.permission.resource.calendar' => ['Calendar', 'Lịch'],
    'text.permission.resource.comment' => ['Comments', 'Bình luận'],
    'text.permission.resource.gallery' => ['Galleries', 'Thư viện ảnh'],
    'text.permission.resource.menu' => ['Menus', 'Menu'],
    'text.permission.resource.menu_item' => ['Menu items', 'Mục menu'],
    'text.permission.resource.language' => ['Languages', 'Ngôn ngữ'],
    'text.permission.resource.setting' => ['Settings', 'Cài đặt'],
    'text.permission.resource.upload' => ['Uploads', 'Tệp tải lên'],
    'text.permission.resource.storage' => ['Storage', 'Kho tệp'],
    'text.permission.resource.tag' => ['Tags', 'Thẻ'],
    'text.permission.resource.translate' => ['Translations', 'Bản dịch'],
    'text.permission.resource.slug' => ['Links', 'Đường dẫn'],
    'text.permission.resource.seo' => ['SEO', 'SEO'],
    'text.permission.resource.field' => ['Forms', 'Biểu mẫu'],
    'text.permission.resource.field_item' => ['Form fields', 'Trường biểu mẫu'],
    'text.permission.resource.place' => ['Places', 'Địa điểm'],
    'text.permission.resource.province' => ['Provinces', 'Tỉnh, thành'],
    'text.permission.resource.audit_log' => ['Audit log', 'Nhật ký thao tác'],
    'text.permission.resource.api_log' => ['API log', 'Nhật ký API'],
    'text.permission.resource.widget' => ['Widgets', 'Widget'],
    'text.permission.resource.webmail' => ['Webmail', 'Webmail'],
    'text.permission.resource.scheduler' => ['Scheduled tasks', 'Tác vụ định kỳ'],
    'text.permission.resource.workflow_project' => ['Workflows', 'Quy trình'],
    'text.permission.resource.workflow_instance' => ['Workflow runs', 'Lượt chạy quy trình'],
    'text.permission.resource.workflow_credential' => ['Workflow credentials', 'Tài khoản kết nối của quy trình'],
    'text.permission.resource.agentic' => ['AI assistant', 'Trợ lý AI'],
    'text.permission.resource.agent_approval' => ['AI approvals', 'Duyệt việc AI đề xuất'],
];

$records = [];
foreach ($labels as $name => [$en, $vi]) {
    $records[] = [
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => $name,
        'text' => ['en' => $en, 'vi' => $vi],
        'for_web' => false,
        'for_adm' => true,
        'for_app' => false,
        'status' => 'status_published',
    ];
}

return $records;
