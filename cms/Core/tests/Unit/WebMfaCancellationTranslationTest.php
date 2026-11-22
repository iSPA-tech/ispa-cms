<?php

uses(\Cms\Core\Tests\TestCase::class);

it('keeps website MFA cancellation translations durable in English and Vietnamese', function (): void {
    $expected = [
        'cancel-authenticator-access' => [
            'en' => 'I can\'t use my authenticator app right now',
            'vi' => 'Tôi không thể dùng ứng dụng xác thực lúc này',
        ],
        'cancel-email-access' => [
            'en' => 'I can\'t access my email right now',
            'vi' => 'Tôi không thể truy cập email lúc này',
        ],
        'cancel-recovery-access' => [
            'en' => 'I don\'t have a recovery code right now',
            'vi' => 'Tôi không có mã khôi phục lúc này',
        ],
        'cancel-sign-in-note' => [
            'en' => 'You will be signed out and the current verification attempt will be cancelled.',
            'vi' => 'Bạn sẽ được đăng xuất và quá trình xác thực hiện tại sẽ bị hủy.',
        ],
    ];

    $payload = collect(require base_path('cms/Core/database/seeders/sql/translate-admin-mfa.php'))
        ->keyBy('name');
    $packs = [
        'en' => require base_path('lang/en/text.php'),
        'vi' => require base_path('lang/vi/text.php'),
        'core_en' => require base_path('cms/Core/lang/en/text.php'),
        'core_vi' => require base_path('cms/Core/lang/vi/text.php'),
    ];

    foreach ($expected as $key => $translations) {
        $row = $payload->get('text.mfa.' . $key);

        expect($row)->not->toBeNull()
            ->and($row['for_web'])->toBe(1)
            ->and($row['for_adm'])->toBe(1)
            ->and(json_decode($row['text'], true, 512, JSON_THROW_ON_ERROR))->toBe($translations)
            ->and($packs['en']['mfa'][$key])->toBe($translations['en'])
            ->and($packs['core_en']['mfa'][$key])->toBe($translations['en'])
            ->and($packs['vi']['mfa'][$key])->toBe($translations['vi'])
            ->and($packs['core_vi']['mfa'][$key])->toBe($translations['vi']);
    }
});

it('authorizes shared MFA mode labels for Core admin and website surfaces', function (): void {
    $expected = [
        'mode-off' => ['en' => 'Password only', 'vi' => 'Chỉ dùng mật khẩu'],
        'mode-email-only' => ['en' => 'Email code only', 'vi' => 'Chỉ dùng mã email'],
        'mode-totp-only' => ['en' => 'Authenticator app only', 'vi' => 'Chỉ dùng ứng dụng xác thực'],
        'mode-email-plus-totp' => [
            'en' => 'Email code + authenticator app',
            'vi' => 'Mã email + ứng dụng xác thực',
        ],
    ];

    $sources = [
        'sql' => collect(require base_path('cms/Core/database/seeders/sql/translate-admin-mfa.php'))->keyBy('name'),
        'exported' => collect(require base_path('cms/Core/database/seeders/exported/translates.php'))->keyBy('name'),
    ];

    foreach ($expected as $key => $translations) {
        foreach ($sources as $source => $payload) {
            $row = $payload->get('text.mfa.' . $key);

            expect($row, "{$source}: text.mfa.{$key}")->not->toBeNull()
                ->and($row['modules'])->toBe('["Core"]')
                ->and($row['for_web'])->toBe(1)
                ->and($row['for_adm'])->toBe(1)
                ->and(json_decode($row['text'], true, 512, JSON_THROW_ON_ERROR))->toBe($translations);
        }
    }
});

it('authorizes shared MFA mode descriptions for Core admin and website surfaces', function (): void {
    $expected = [
        'mode-off-desc' => [
            'en' => 'Sign in with your password without an additional verification step.',
            'vi' => 'Đăng nhập bằng mật khẩu, không yêu cầu bước xác minh bổ sung.',
        ],
        'mode-email-only-desc' => [
            'en' => 'Receive a one-time verification code through your account email.',
            'vi' => 'Nhận mã xác minh dùng một lần qua email của tài khoản.',
        ],
        'mode-totp-only-desc' => [
            'en' => 'Use a 6-digit code from your authenticator app after password sign-in.',
            'vi' => 'Dùng mã 6 số từ ứng dụng xác thực sau khi đăng nhập bằng mật khẩu.',
        ],
        'mode-email-plus-totp-desc' => [
            'en' => 'Require both an email code and an authenticator app code.',
            'vi' => 'Yêu cầu cả mã email và mã từ ứng dụng xác thực.',
        ],
    ];

    $sources = [
        'sql' => collect(require base_path('cms/Core/database/seeders/sql/translate-admin-mfa.php'))->keyBy('name'),
        'sidecar' => collect(require base_path('cms/Core/database/seeders/exported/translates-admin-account-authenticator-help.php'))->keyBy('name'),
    ];
    $packs = [
        'en' => require base_path('lang/en/text.php'),
        'vi' => require base_path('lang/vi/text.php'),
        'core_en' => require base_path('cms/Core/lang/en/text.php'),
        'core_vi' => require base_path('cms/Core/lang/vi/text.php'),
    ];

    foreach ($expected as $key => $translations) {
        foreach ($sources as $source => $payload) {
            $row = $payload->get('text.mfa.' . $key);
            $modules = is_string($row['modules'] ?? null)
                ? json_decode($row['modules'], true, 512, JSON_THROW_ON_ERROR)
                : ($row['modules'] ?? []);
            $text = is_string($row['text'] ?? null)
                ? json_decode($row['text'], true, 512, JSON_THROW_ON_ERROR)
                : ($row['text'] ?? []);

            expect($row, "{$source}: text.mfa.{$key}")->not->toBeNull()
                ->and($modules)->toBe(['Core'])
                ->and($row['for_web'])->toBe(1)
                ->and($row['for_adm'])->toBe(1)
                ->and($text)->toBe($translations);
        }

        expect($packs['en']['mfa'][$key])->toBe($translations['en'])
            ->and($packs['core_en']['mfa'][$key])->toBe($translations['en'])
            ->and($packs['vi']['mfa'][$key])->toBe($translations['vi'])
            ->and($packs['core_vi']['mfa'][$key])->toBe($translations['vi']);
    }
});

it('authorizes shared authenticator help copy for Core admin and website surfaces', function (): void {
    $expected = [
        'authenticator-help-desc' => [
            'en' => 'An authenticator app creates a new 6-digit code every 30 seconds, protecting your account even if the password is exposed.',
            'vi' => 'Ứng dụng xác thực tạo mã 6 số mới sau mỗi 30 giây để bảo vệ tài khoản ngay cả khi mật khẩu bị lộ.',
        ],
        'authenticator-help-title' => [
            'en' => 'How to set up an authenticator app',
            'vi' => 'Cách thiết lập ứng dụng xác thực',
        ],
        'authenticator-help-install' => [
            'en' => 'Install Authy, Google Authenticator, or Microsoft Authenticator on your phone.',
            'vi' => 'Cài Authy, Google Authenticator hoặc Microsoft Authenticator trên điện thoại.',
        ],
        'authenticator-help-start' => [
            'en' => 'Select Set up authenticator and verify your identity with your current password.',
            'vi' => 'Chọn Thiết lập xác thực và xác minh danh tính bằng mật khẩu hiện tại.',
        ],
        'authenticator-help-scan' => [
            'en' => 'Add an account in the app and scan the QR code; enter the manual key if scanning is unavailable.',
            'vi' => 'Trong ứng dụng, thêm tài khoản rồi quét mã QR; nếu không quét được, nhập khóa thủ công.',
        ],
        'authenticator-help-confirm' => [
            'en' => 'Enter the current 6-digit code from the app to confirm setup.',
            'vi' => 'Nhập mã 6 số đang hiển thị trong ứng dụng để xác nhận thiết lập.',
        ],
        'authenticator-help-recovery' => [
            'en' => 'Store recovery codes privately, preferably on paper or in a password manager.',
            'vi' => 'Lưu mã khôi phục ở nơi riêng tư, ưu tiên bản giấy hoặc trình quản lý mật khẩu.',
        ],
        'authenticator-help-warning' => [
            'en' => 'Never share the QR code, manual key, or recovery codes with anyone.',
            'vi' => 'Không chia sẻ mã QR, khóa thủ công hoặc mã khôi phục cho bất kỳ ai.',
        ],
        'authenticator-help-link' => [
            'en' => 'Read the full guide',
            'vi' => 'Xem hướng dẫn đầy đủ',
        ],
    ];

    $sources = [
        'sql' => collect(require base_path('cms/Core/database/seeders/sql/translate-admin-mfa.php'))->keyBy('name'),
        'sidecar' => collect(require base_path('cms/Core/database/seeders/exported/translates-admin-account-authenticator-help.php'))->keyBy('name'),
    ];
    $packs = [
        'en' => require base_path('lang/en/text.php'),
        'vi' => require base_path('lang/vi/text.php'),
        'core_en' => require base_path('cms/Core/lang/en/text.php'),
        'core_vi' => require base_path('cms/Core/lang/vi/text.php'),
    ];

    foreach ($expected as $key => $translations) {
        foreach ($sources as $source => $payload) {
            $row = $payload->get('text.mfa.' . $key);
            $modules = is_string($row['modules'] ?? null)
                ? json_decode($row['modules'], true, 512, JSON_THROW_ON_ERROR)
                : ($row['modules'] ?? []);
            $text = is_string($row['text'] ?? null)
                ? json_decode($row['text'], true, 512, JSON_THROW_ON_ERROR)
                : ($row['text'] ?? []);

            expect($row, "{$source}: text.mfa.{$key}")->not->toBeNull()
                ->and($modules)->toBe(['Core'])
                ->and($row['for_web'])->toBe(1)
                ->and($row['for_adm'])->toBe(1)
                ->and($text)->toBe($translations);
        }

        expect($packs['en']['mfa'][$key])->toBe($translations['en'])
            ->and($packs['core_en']['mfa'][$key])->toBe($translations['en'])
            ->and($packs['vi']['mfa'][$key])->toBe($translations['vi'])
            ->and($packs['core_vi']['mfa'][$key])->toBe($translations['vi']);
    }
});
