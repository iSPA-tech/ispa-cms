<?php

use Tests\TestCase;

uses(TestCase::class);

test('users without avatar media receive the guaranteed shared avatar asset', function (): void {
    expect(user_avatar_url(null))->toBe(asset('images/avatar.svg'))
        ->and(public_path('images/avatar.svg'))->toBeFile();
});

test('uploaded avatar media keeps precedence over the shared fallback', function (): void {
    expect(user_avatar_url([
        'avatar' => [
            'thumbnail_sm' => 'https://cdn.example.test/avatar-sm.webp',
            'url' => 'https://cdn.example.test/avatar.webp',
        ],
    ]))->toBe('https://cdn.example.test/avatar-sm.webp');
});
