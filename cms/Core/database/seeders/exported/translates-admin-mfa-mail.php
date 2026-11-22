<?php

/**
 * Server-rendered translations for the admin MFA email flow.
 *
 * @return array<int, array<string, mixed>>
 */

$entry = static function (string $name, string $en, string $vi): array {
    return [
        'modules' => ['Core'],
        'group_name' => 'mgs',
        'name' => $name,
        'text' => [
            'en' => $en,
            'vi' => $vi,
        ],
        'for_web' => 1,
        'for_adm' => 0,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-08-17 00:00:00',
        'updated_at' => '2026-08-17 00:00:00',
    ];
};

return [
    $entry('mgs.code.two_fa', 'Two-factor authentication code', 'Mã xác thực hai bước'),
    $entry('mgs.mail.cannot-view', 'Cannot view this email?', 'Không xem được email này?'),
    $entry('mgs.mail.default-recipient', 'Administrator', 'Quản trị viên'),
    $entry('mgs.mail.footer-received', 'You received this email from :brand.', 'Bạn nhận được email này từ :brand.'),
    $entry('mgs.mail.footer-sent-at', 'Email sent at :time.', 'Email được gửi lúc :time.'),
    $entry('mgs.mail.two-fa-intro', 'Hello :name, use the verification code below to continue signing in to :brand. This code expires in 5 minutes.', 'Xin chào :name, hãy sử dụng mã xác thực bên dưới để tiếp tục đăng nhập vào :brand. Mã này hết hạn sau 5 phút.'),
    $entry('mgs.mail.two-fa-security', 'If you did not start this sign-in, do not share this code. Change your password and contact an administrator immediately if you suspect unauthorized access.', 'Nếu bạn không thực hiện lần đăng nhập này, đừng chia sẻ mã. Hãy đổi mật khẩu và liên hệ quản trị viên ngay nếu nghi ngờ có truy cập trái phép.'),
    $entry('mgs.mail.verification-code', 'Verification code', 'Mã xác thực'),
    $entry('mgs.mail.view-live', 'View online', 'Xem trực tuyến'),
];
