<?php

uses(\Cms\Core\Tests\TestCase::class);

it('provides durable English and Vietnamese translations for admin MFA mail', function (): void {
    $rows = require base_path('cms/Core/database/seeders/exported/translates-admin-mfa-mail.php');
    $byName = collect($rows)->keyBy('name');

    $expected = [
        'mgs.code.two_fa' => [
            'en' => 'Two-factor authentication code',
            'vi' => 'Mã xác thực hai bước',
        ],
        'mgs.mail.footer-received' => [
            'en' => 'You received this email from :brand.',
            'vi' => 'Bạn nhận được email này từ :brand.',
        ],
        'mgs.mail.footer-sent-at' => [
            'en' => 'Email sent at :time.',
            'vi' => 'Email được gửi lúc :time.',
        ],
        'mgs.mail.cannot-view' => [
            'en' => 'Cannot view this email?',
            'vi' => 'Không xem được email này?',
        ],
        'mgs.mail.default-recipient' => [
            'en' => 'Administrator',
            'vi' => 'Quản trị viên',
        ],
        'mgs.mail.two-fa-intro' => [
            'en' => 'Hello :name, use the verification code below to continue signing in to :brand. This code expires in 5 minutes.',
            'vi' => 'Xin chào :name, hãy sử dụng mã xác thực bên dưới để tiếp tục đăng nhập vào :brand. Mã này hết hạn sau 5 phút.',
        ],
        'mgs.mail.two-fa-security' => [
            'en' => 'If you did not start this sign-in, do not share this code. Change your password and contact an administrator immediately if you suspect unauthorized access.',
            'vi' => 'Nếu bạn không thực hiện lần đăng nhập này, đừng chia sẻ mã. Hãy đổi mật khẩu và liên hệ quản trị viên ngay nếu nghi ngờ có truy cập trái phép.',
        ],
        'mgs.mail.verification-code' => [
            'en' => 'Verification code',
            'vi' => 'Mã xác thực',
        ],
        'mgs.mail.view-live' => [
            'en' => 'View online',
            'vi' => 'Xem trực tuyến',
        ],
    ];

    expect($rows)->toHaveCount(count($expected));
    expect($byName)->toHaveCount(count($expected));

    $packs = [
        'root_en' => require base_path('lang/en/mgs.php'),
        'root_vi' => require base_path('lang/vi/mgs.php'),
        'core_en' => require base_path('cms/Core/lang/en/mgs.php'),
        'core_vi' => require base_path('cms/Core/lang/vi/mgs.php'),
    ];

    foreach ($expected as $name => $text) {
        $record = $byName->get($name);

        expect($record)->not->toBeNull()
            ->and($record)->not->toHaveKey('id')
            ->and($record['modules'])->toBe(['Core'])
            ->and($record['group_name'])->toBe('mgs')
            ->and($record['for_adm'])->toBe(0)
            ->and($record['for_web'])->toBe(1)
            ->and($record['for_app'])->toBe(0)
            ->and($record['status'])->toBe('status_published')
            ->and($record['text'])->toBe($text);

        [, $group, $key] = explode('.', $name, 3);
        expect($packs['root_en'][$group][$key])->toBe($text['en'])
            ->and($packs['core_en'][$group][$key])->toBe($text['en'])
            ->and($packs['root_vi'][$group][$key])->toBe($text['vi'])
            ->and($packs['core_vi'][$group][$key])->toBe($text['vi']);
    }
});
