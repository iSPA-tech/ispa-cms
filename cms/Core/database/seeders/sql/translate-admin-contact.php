<?php

/**
 * Contact mailbox admin translations.
 */

$entry = static function (
    int $id,
    string $name,
    string $en,
    string $vi,
): array {
    return [
        // No 'id': the importer finds rows by name, and an id only links an existing row that may hold another key.
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => $name,
        'text' => [
            'en' => $en,
            'vi' => $vi,
        ],
        'for_web' => 0,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-08-18 14:00:00',
        'updated_at' => '2026-08-18 14:00:00',
    ];
};

return [
    $entry(994750, 'text.contact.email.spam.confirm', 'Mark :count selected inbox messages as spam? All inbox messages from the same sender will also be removed from the mailbox.', 'Đánh dấu :count email inbox đã chọn là spam? Toàn bộ email inbox cùng địa chỉ người gửi cũng sẽ bị xóa khỏi hộp thư.'),
    $entry(994751, 'text.contact.email.spam.block_custom_domain', 'Block the sender\'s custom domain; Gmail, Hotmail, Outlook, and Yahoo are excluded.', 'Chặn toàn bộ domain riêng; Gmail, Hotmail, Outlook và Yahoo được loại trừ.'),
    $entry(994752, 'text.contact.email.spam.action', 'Mark selected emails as spam', 'Đánh dấu các email đã chọn là spam'),
    $entry(994753, 'text.contact.email.spam.failed', 'Unable to mark emails as spam.', 'Không thể đánh dấu email là spam.'),
];
