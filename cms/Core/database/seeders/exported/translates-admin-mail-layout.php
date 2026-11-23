<?php

/**
 * Server-rendered translations for the shared branded mail layout (header, footer, notification template).
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
        'created_at' => '2026-11-23 00:00:00',
        'updated_at' => '2026-11-23 00:00:00',
    ];
};

return [
    $entry('mgs.mail.button-trouble', 'If the ":actionText" button does not open, copy the link below into your browser:', 'Nếu nút ":actionText" không mở được, hãy sao chép đường dẫn dưới đây và dán vào trình duyệt:'),
    $entry('mgs.mail.footer-copyright', '© :year :brand. All rights reserved.', '© :year :brand. Bảo lưu mọi quyền.'),
    $entry('mgs.mail.hello', 'Hello,', 'Xin chào,'),
    $entry('mgs.mail.regards', 'Regards,', 'Trân trọng,'),
    $entry('mgs.mail.whoops', 'Something went wrong', 'Đã có lỗi xảy ra'),
    $entry('mgs.mail.admin-notice-line', 'Sign in to the admin panel to see the details.', 'Hãy đăng nhập trang quản trị để xem chi tiết.'),
    $entry('mgs.mail.admin-notice-subject', 'You have a new notification in the admin panel', 'Bạn có thông báo mới trong trang quản trị'),
    $entry('mgs.mail.auto-password-hidden', 'Your new password is not shown here. Please check the email sent to you.', 'Mật khẩu mới không hiển thị ở đây. Hãy xem trong email đã gửi cho bạn.'),
    $entry('mgs.mail.auto-password-line', 'Your new password:', 'Mật khẩu mới của bạn:'),
    $entry('mgs.mail.auto-password-note', 'Sign in with this password, then change it to one only you know.', 'Hãy đăng nhập bằng mật khẩu này rồi đổi sang mật khẩu chỉ bạn biết.'),
    $entry('mgs.mail.auto-password-subject', 'New password for :email', 'Mật khẩu mới cho tài khoản :email'),
    $entry('mgs.mail.contact-reply-subject', 'Reply to your contact message', 'Phản hồi liên hệ của bạn'),
    $entry('mgs.mail.new-contact-admin-link', 'Admin panel:', 'Trang quản trị:'),
    $entry('mgs.mail.new-contact-intro', 'A customer with the email ":email" just sent a new contact message. Open the admin panel to read it.', 'Khách hàng có email ":email" vừa gửi một liên hệ mới. Hãy vào trang quản trị để xem.'),
    $entry('mgs.mail.new-contact-subject', 'New contact message', 'Có liên hệ mới'),
    $entry('mgs.mail.password-changed-at', 'Changed at', 'Thời gian đổi'),
    $entry('mgs.mail.password-changed-details', 'Details of the change', 'Thông tin lần đổi mật khẩu'),
    $entry('mgs.mail.password-changed-device', 'Device', 'Thiết bị'),
    $entry('mgs.mail.password-changed-intro', 'The password of your :brand account was just changed. If you did not do this, reset your password now.', 'Mật khẩu tài khoản :brand của bạn vừa được đổi. Nếu không phải bạn đổi, hãy đặt lại mật khẩu ngay.'),
    $entry('mgs.mail.password-changed-ip', 'IP address', 'Địa chỉ IP'),
    $entry('mgs.mail.password-changed-platform', 'Platform', 'Hệ điều hành'),
    $entry('mgs.mail.password-changed-subject', 'Your account password was changed', 'Mật khẩu tài khoản của bạn vừa được đổi'),
    $entry('mgs.mail.password-changed-support', 'Need help? Contact :support.', 'Cần hỗ trợ, hãy liên hệ :support.'),
    $entry('mgs.mail.reset-code-ignore', 'If you did not ask for this, ignore this email. Your password stays the same.', 'Nếu bạn không yêu cầu, hãy bỏ qua email này. Mật khẩu của bạn vẫn giữ nguyên.'),
    $entry('mgs.mail.reset-code-intro', 'Hello :name. You (or someone else) just asked to recover the password of your :brand account.', 'Xin chào :name. Bạn (hoặc ai đó) vừa yêu cầu khôi phục mật khẩu tài khoản :brand của bạn.'),
    $entry('mgs.mail.reset-code-label', 'Your code:', 'Mã của bạn:'),
    $entry('mgs.mail.verify-email-intro', 'Press the button below to confirm your email address.', 'Hãy bấm nút bên dưới để xác nhận địa chỉ email của bạn.'),
];
