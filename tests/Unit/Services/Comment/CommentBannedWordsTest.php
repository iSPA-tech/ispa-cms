<?php

declare(strict_types=1);

use App\Services\Comment\CommentBannedWords;

test('a banned word matches whatever its case or Vietnamese accents', function () {
    expect(CommentBannedWords::find(['lừa đảo'], 'Trang này LUA DAO đấy'))->toBe(['lừa đảo'])
        ->and(CommentBannedWords::find(['lua dao'], 'Cẩn thận lừa đảo!'))->toBe(['lua dao'])
        ->and(CommentBannedWords::find(['Đánh bạc'], 'không danh bac nhé'))->toBe(['Đánh bạc']);
});

test('only whole words and phrases match', function () {
    expect(CommentBannedWords::find(['spam'], 'antispamming tools'))->toBe([])
        ->and(CommentBannedWords::find(['spam'], 'đây là spam.'))->toBe(['spam'])
        ->and(CommentBannedWords::find(['mua ngay'], 'Mua   ngay tại đây'))->toBe(['mua ngay']);
});

test('the sender name is checked as well as the text, and each word is listed once', function () {
    expect(CommentBannedWords::find(['casino', 'spam'], 'Casino Online', 'casino casino', null))->toBe(['casino']);
});

test('an empty list or empty text matches nothing', function () {
    expect(CommentBannedWords::find([], 'anything'))->toBe([])
        ->and(CommentBannedWords::find(['spam'], null, ''))->toBe([]);
});

test('a saved list is trimmed, blank and repeated lines dropped, and bounded', function () {
    $list = CommentBannedWords::normalize(['  Spam ', '', 'spam', 'SPAM', 'lừa   đảo', str_repeat('x', 150), "  \t "]);

    expect($list)->toBe(['Spam', 'lừa đảo', str_repeat('x', 100)])
        ->and(CommentBannedWords::normalize(array_map(static fn (int $n): string => "word{$n}", range(1, 600))))->toHaveCount(500);
});
