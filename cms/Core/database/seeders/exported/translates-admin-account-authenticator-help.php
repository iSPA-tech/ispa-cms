<?php

/**
 * Shared admin and website copy for account MFA controls and authenticator enrollment.
 *
 * @return array<int, array<string, mixed>>
 */

$entry = static function (string $name, string $en, string $vi): array {
    return [
        'modules' => ['Core'],
        'group_name' => 'text',
        'name' => $name,
        'text' => ['en' => $en, 'vi' => $vi],
        'for_web' => 1,
        'for_adm' => 1,
        'for_app' => 0,
        'status' => 'status_published',
        'created_at' => '2026-08-24 00:00:00',
        'updated_at' => '2026-08-24 00:00:00',
    ];
};

return [
    $entry('text.mfa.mode-off-desc', 'Sign in with your password without an additional verification step.', 'Đăng nhập bằng mật khẩu, không yêu cầu bước xác minh bổ sung.'),
    $entry('text.mfa.mode-email-only-desc', 'Receive a one-time verification code through your account email.', 'Nhận mã xác minh dùng một lần qua email của tài khoản.'),
    $entry('text.mfa.mode-totp-only-desc', 'Use a 6-digit code from your authenticator app after password sign-in.', 'Dùng mã 6 số từ ứng dụng xác thực sau khi đăng nhập bằng mật khẩu.'),
    $entry('text.mfa.mode-email-plus-totp-desc', 'Require both an email code and an authenticator app code.', 'Yêu cầu cả mã email và mã từ ứng dụng xác thực.'),
    $entry('text.mfa.authenticator-help-desc', 'An authenticator app creates a new 6-digit code every 30 seconds, protecting your account even if the password is exposed.', 'Ứng dụng xác thực tạo mã 6 số mới sau mỗi 30 giây để bảo vệ tài khoản ngay cả khi mật khẩu bị lộ.'),
    $entry('text.mfa.authenticator-help-title', 'How to set up an authenticator app', 'Cách thiết lập ứng dụng xác thực'),
    $entry('text.mfa.authenticator-help-install', 'Install Authy, Google Authenticator, or Microsoft Authenticator on your phone.', 'Cài Authy, Google Authenticator hoặc Microsoft Authenticator trên điện thoại.'),
    $entry('text.mfa.authenticator-help-start', 'Select Set up authenticator and verify your identity with your current password.', 'Chọn Thiết lập xác thực và xác minh danh tính bằng mật khẩu hiện tại.'),
    $entry('text.mfa.authenticator-help-scan', 'Add an account in the app and scan the QR code; enter the manual key if scanning is unavailable.', 'Trong ứng dụng, thêm tài khoản rồi quét mã QR; nếu không quét được, nhập khóa thủ công.'),
    $entry('text.mfa.authenticator-help-confirm', 'Enter the current 6-digit code from the app to confirm setup.', 'Nhập mã 6 số đang hiển thị trong ứng dụng để xác nhận thiết lập.'),
    $entry('text.mfa.authenticator-help-recovery', 'Store recovery codes privately, preferably on paper or in a password manager.', 'Lưu mã khôi phục ở nơi riêng tư, ưu tiên bản giấy hoặc trình quản lý mật khẩu.'),
    $entry('text.mfa.authenticator-help-warning', 'Never share the QR code, manual key, or recovery codes with anyone.', 'Không chia sẻ mã QR, khóa thủ công hoặc mã khôi phục cho bất kỳ ai.'),
    $entry('text.mfa.authenticator-help-link', 'Read the full guide', 'Xem hướng dẫn đầy đủ'),
];
