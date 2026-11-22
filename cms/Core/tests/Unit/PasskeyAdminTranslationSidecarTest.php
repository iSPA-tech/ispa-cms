<?php

uses(\Cms\Core\Tests\TestCase::class);

it('provides complete passkey translations for the admin and web packs', function (): void {
    $rows = require base_path('cms/Core/database/seeders/exported/translates-admin-passkey.php');
    $byName = collect($rows)->keyBy('name');

    $expected = [
        'text.passkey.signing-in' => [
            'en' => 'Signing in with passkey...',
            'vi' => 'Đang xác thực passkey...',
        ],
        'text.passkey.sign-in' => [
            'en' => 'Sign in with a passkey',
            'vi' => 'Đăng nhập bằng passkey',
        ],
        'text.passkey.sign-in-desc' => [
            'en' => 'Use Face ID, Touch ID, or a security key.',
            'vi' => 'Dùng Face ID, Touch ID hoặc khóa bảo mật.',
        ],
        'text.passkey.cancelled-title' => [
            'en' => 'Cancelled',
            'vi' => 'Đã hủy',
        ],
        'text.passkey.cancelled' => [
            'en' => 'Passkey verification was cancelled.',
            'vi' => 'Đã hủy xác thực passkey.',
        ],
    ];

    expect($byName)->toHaveCount(count($expected));

    foreach ($expected as $name => $text) {
        $record = $byName->get($name);

        expect($record)->not->toBeNull()
            ->and($record['modules'])->toBe(['Core'])
            ->and($record['group_name'])->toBe('text')
            ->and($record['for_adm'])->toBe(1)
            ->and($record['for_web'])->toBe(1)
            ->and($record['for_app'])->toBe(0)
            ->and($record['text'])->toBe($text);
    }
});
