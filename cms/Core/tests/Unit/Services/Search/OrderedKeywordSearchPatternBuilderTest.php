<?php

use Cms\Core\Services\Search\OrderedKeywordSearchPatternBuilder;

test('builds ordered sensitive and folded patterns from normalized whitespace', function () {
    $patterns = (new OrderedKeywordSearchPatternBuilder)->build("  NHIỆM\t vụ\n tr  ");

    expect($patterns)->toBe([
        ['tier' => 0, 'mode' => 'sensitive', 'pattern' => '%nhiệm vụ tr%'],
        ['tier' => 1, 'mode' => 'sensitive', 'pattern' => '%nhiệm%vụ%tr%'],
        ['tier' => 2, 'mode' => 'folded', 'pattern' => '%nhiem vu tr%'],
        ['tier' => 3, 'mode' => 'folded', 'pattern' => '%nhiem%vu%tr%'],
    ]);
});

test('blank input is a no-op', function (?string $keyword) {
    expect((new OrderedKeywordSearchPatternBuilder)->build($keyword))->toBe([]);
})->with([null, '', '   ', "\t\n"]);

test('preserves the requested token order', function () {
    $patterns = (new OrderedKeywordSearchPatternBuilder)->build('tr nhiệm vụ');

    expect($patterns)
        ->toContain(['tier' => 1, 'mode' => 'sensitive', 'pattern' => '%tr%nhiệm%vụ%'])
        ->not->toContain(['tier' => 1, 'mode' => 'sensitive', 'pattern' => '%nhiệm%vụ%tr%']);
});

test('deduplicates one-token phrase and ordered patterns within each mode', function () {
    expect((new OrderedKeywordSearchPatternBuilder)->build('NHIỆM'))->toBe([
        ['tier' => 0, 'mode' => 'sensitive', 'pattern' => '%nhiệm%'],
        ['tier' => 2, 'mode' => 'folded', 'pattern' => '%nhiem%'],
    ]);
});

test('keeps sensitive and folded modes for accentless input', function () {
    expect((new OrderedKeywordSearchPatternBuilder)->build('nhiem'))->toBe([
        ['tier' => 0, 'mode' => 'sensitive', 'pattern' => '%nhiem%'],
        ['tier' => 2, 'mode' => 'folded', 'pattern' => '%nhiem%'],
    ]);
});

test('escapes wildcard and escape characters while leaving backslash literal', function () {
    expect((new OrderedKeywordSearchPatternBuilder)->build('100%_đúng!\\'))->toBe([
        ['tier' => 0, 'mode' => 'sensitive', 'pattern' => '%100!%!_đúng!!\\%'],
        ['tier' => 2, 'mode' => 'folded', 'pattern' => '%100!%!_dung!!\\%'],
    ]);
});

test('folds every canonical Vietnamese group after mixed-case lowercasing', function () {
    $builder = new OrderedKeywordSearchPatternBuilder;

    expect($builder->fold('ÀáẠảÃÂầẤậẨẫĂằẮặẲẵ'))->toBe(str_repeat('a', 17))
        ->and($builder->fold('ÈéẸẻẼÊềẾệỂễ'))->toBe(str_repeat('e', 11))
        ->and($builder->fold('ÌíỊỉĨ'))->toBe(str_repeat('i', 5))
        ->and($builder->fold('ÒóỌỏÕÔồỐộỔỗƠờỚợỞỡ'))->toBe(str_repeat('o', 17))
        ->and($builder->fold('ÙúỤủŨƯừỨựỬữ'))->toBe(str_repeat('u', 11))
        ->and($builder->fold('ỲýỴỷỸ'))->toBe(str_repeat('y', 5))
        ->and($builder->fold('Đđ'))->toBe('dd');
});

test('exports aligned PostgreSQL translate strings from the canonical fold table', function () {
    $builder = new OrderedKeywordSearchPatternBuilder;

    expect(mb_strlen($builder->postgresTranslateFrom()))
        ->toBe(mb_strlen($builder->postgresTranslateTo()))
        ->and($builder->fold($builder->postgresTranslateFrom()))
        ->toBe($builder->postgresTranslateTo());
});

test('rejects invalid UTF-8', function () {
    (new OrderedKeywordSearchPatternBuilder)->build("invalid\xC3\x28");
})->throws(InvalidArgumentException::class);
