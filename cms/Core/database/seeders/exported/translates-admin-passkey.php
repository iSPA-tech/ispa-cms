<?php

/**
 * Admin and web authentication translations for the passkey sign-in flow.
 *
 * Rows carry no fixed id: an id only links an existing row, and ids 6938-6942 belong to other keys in some databases.
 *
 * @return array<int, array<string, mixed>>
 */

$entry = static function (string $name, string $en, string $vi): array {
    return [
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => $name,
        'text' => [
            'en' => $en,
            'vi' => $vi,
        ],
        'for_web' => 1,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-08-12 00:00:00',
        'updated_at' => '2026-08-12 00:00:00',
    ];
};

return [
    $entry('text.passkey.signing-in', 'Signing in with passkey...', 'Đang xác thực passkey...'),
    $entry('text.passkey.sign-in', 'Sign in with a passkey', 'Đăng nhập bằng passkey'),
    $entry('text.passkey.sign-in-desc', 'Use Face ID, Touch ID, or a security key.', 'Dùng Face ID, Touch ID hoặc khóa bảo mật.'),
    $entry('text.passkey.cancelled-title', 'Cancelled', 'Đã hủy'),
    $entry('text.passkey.cancelled', 'Passkey verification was cancelled.', 'Đã hủy xác thực passkey.'),
];
