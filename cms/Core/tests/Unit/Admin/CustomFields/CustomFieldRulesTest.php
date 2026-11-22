<?php

use Cms\Core\Admin\CustomFields\CustomFieldRules;
use Cms\Core\Admin\CustomFields\CustomFieldType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Turn a Laravel rule list into comparable strings: Rule::in becomes its string form, rule objects a marker.
 *
 * @param  array<int, mixed>  $rules
 * @return list<string>
 */
function customFieldRuleStrings(array $rules): array
{
    return array_values(array_map(static function (mixed $rule): string {
        if ($rule instanceof In) {
            return (string) $rule;
        }
        if ($rule instanceof ValidationRule) {
            return '#object';
        }

        return (string) $rule;
    }, $rules));
}

/**
 * Run the real validator for one custom field value.
 *
 * @param  array<string, mixed>  $rules
 * @param  list<string>  $options
 */
function customFieldPasses(CustomFieldType $type, array $rules, bool $required, mixed $value, array $options = []): bool
{
    $data = $value === '__absent__' ? [] : ['value' => $value];

    return Validator::make($data, ['value' => (new CustomFieldRules)->toLaravel($type, $rules, $required, $options)])->passes();
}

it('maps each type to meta attribute storage', function () {
    expect(CustomFieldType::Text->metaType())->toBe('string')
        ->and(CustomFieldType::Textarea->metaType())->toBe('text')
        ->and(CustomFieldType::Integer->metaType())->toBe('bigInt')
        ->and(CustomFieldType::Boolean->metaType())->toBe('boolean')
        ->and(CustomFieldType::Date->metaType())->toBe('date')
        ->and(CustomFieldType::Select->metaType())->toBe('string')
        ->and(CustomFieldType::Multiselect->metaType())->toBe('string');

    foreach (CustomFieldType::cases() as $case) {
        expect($case->isMultiple())->toBe($case === CustomFieldType::Multiselect)
            ->and($case->inputType())->toBeString()->not->toBe('');
    }

    expect(CustomFieldType::Text->allowedRules())->toBe(['min', 'max', 'pattern'])
        ->and(CustomFieldType::Boolean->allowedRules())->toBe([])
        ->and(CustomFieldType::Date->allowedRules())->toBe(['date_min', 'date_max'])
        ->and(CustomFieldType::Multiselect->allowedRules())->toBe(['max']);
});

it('builds exact Laravel rules for text', function () {
    $r = new CustomFieldRules;

    expect(customFieldRuleStrings($r->toLaravel(CustomFieldType::Text, [], false, [])))
        ->toBe(['nullable', 'string', 'max:500'])
        ->and(customFieldRuleStrings($r->toLaravel(CustomFieldType::Text, ['min' => 2, 'max' => 10, 'pattern' => '[a-z]+'], true, [])))
        ->toBe(['required', 'string', 'min:2', 'max:10', '#object']);

    expect(customFieldPasses(CustomFieldType::Text, ['min' => 2, 'max' => 10, 'pattern' => '[a-z]+'], true, 'abc'))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Text, ['min' => 2, 'max' => 10, 'pattern' => '[a-z]+'], true, 'ABC'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Text, ['min' => 2, 'max' => 10, 'pattern' => '[a-z]+'], true, 'a'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Text, ['min' => 2, 'max' => 10, 'pattern' => '[a-z]+'], true, str_repeat('a', 11)))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Text, [], true, '__absent__'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Text, [], false, null))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Text, [], false, str_repeat('a', 501)))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Text, [], false, ['a']))->toBeFalse();
});

it('builds exact Laravel rules for textarea', function () {
    $r = new CustomFieldRules;

    expect(customFieldRuleStrings($r->toLaravel(CustomFieldType::Textarea, [], false, [])))
        ->toBe(['nullable', 'string', 'max:20000'])
        ->and(customFieldRuleStrings($r->toLaravel(CustomFieldType::Textarea, ['min' => 5, 'max' => 20000], true, [])))
        ->toBe(['required', 'string', 'min:5', 'max:20000']);

    expect(customFieldPasses(CustomFieldType::Textarea, ['min' => 5], true, 'hello'))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Textarea, ['min' => 5], true, 'hey'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Textarea, [], false, str_repeat('a', 20001)))->toBeFalse();
});

it('builds exact Laravel rules for integer', function () {
    $r = new CustomFieldRules;

    expect(customFieldRuleStrings($r->toLaravel(CustomFieldType::Integer, [], false, [])))
        ->toBe(['nullable', 'integer'])
        ->and(customFieldRuleStrings($r->toLaravel(CustomFieldType::Integer, ['min' => -5, 'max' => 100], true, [])))
        ->toBe(['required', 'integer', 'min:-5', 'max:100']);

    expect(customFieldPasses(CustomFieldType::Integer, ['min' => -5, 'max' => 100], true, 42))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Integer, ['min' => -5, 'max' => 100], true, '-5'))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Integer, ['min' => -5, 'max' => 100], true, 101))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Integer, ['min' => -5, 'max' => 100], true, -6))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Integer, [], true, '1.5'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Integer, [], true, 'abc'))->toBeFalse();
});

it('builds exact Laravel rules for boolean', function () {
    expect(customFieldRuleStrings((new CustomFieldRules)->toLaravel(CustomFieldType::Boolean, [], true, [])))
        ->toBe(['required', 'boolean']);

    expect(customFieldPasses(CustomFieldType::Boolean, [], true, true))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Boolean, [], true, '0'))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Boolean, [], true, 'yes'))->toBeFalse();
});

it('builds exact Laravel rules for date', function () {
    $r = new CustomFieldRules;

    expect(customFieldRuleStrings($r->toLaravel(CustomFieldType::Date, [], false, [])))
        ->toBe(['nullable', 'date_format:Y-m-d'])
        ->and(customFieldRuleStrings($r->toLaravel(CustomFieldType::Date, ['date_min' => '2026-01-01', 'date_max' => '2026-12-31'], true, [])))
        ->toBe(['required', 'date_format:Y-m-d', 'after_or_equal:2026-01-01', 'before_or_equal:2026-12-31']);

    $rules = ['date_min' => '2026-01-01', 'date_max' => '2026-12-31'];
    expect(customFieldPasses(CustomFieldType::Date, $rules, true, '2026-01-01'))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Date, $rules, true, '2026-12-31'))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Date, $rules, true, '2025-12-31'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Date, $rules, true, '2027-01-01'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Date, [], true, '01/02/2026'))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Date, [], true, 'tomorrow'))->toBeFalse();
});

it('builds exact Laravel rules for select', function () {
    expect(customFieldRuleStrings((new CustomFieldRules)->toLaravel(CustomFieldType::Select, [], true, ['red', 'blue'])))
        ->toBe(['required', 'string', 'in:"red","blue"']);

    expect(customFieldPasses(CustomFieldType::Select, [], true, 'red', ['red', 'blue']))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Select, [], true, 'green', ['red', 'blue']))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Select, [], true, ['red'], ['red', 'blue']))->toBeFalse();
});

it('builds exact Laravel rules for multiselect', function () {
    expect(customFieldRuleStrings((new CustomFieldRules)->toLaravel(CustomFieldType::Multiselect, ['max' => 2], true, ['a', 'b', 'c'])))
        ->toBe(['required', 'array', 'max:2', 'in:"a","b","c"', '#object']);

    $opts = ['a', 'b', 'c'];
    expect(customFieldPasses(CustomFieldType::Multiselect, ['max' => 2], true, ['a', 'c'], $opts))->toBeTrue()
        ->and(customFieldPasses(CustomFieldType::Multiselect, ['max' => 2], true, ['a', 'x'], $opts))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Multiselect, ['max' => 2], true, ['a', 'b', 'c'], $opts))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Multiselect, [], true, ['a', 'a'], $opts))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Multiselect, [], true, [['a']], $opts))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Multiselect, [], true, [], $opts))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Multiselect, [], true, 'a', $opts))->toBeFalse()
        ->and(customFieldPasses(CustomFieldType::Multiselect, [], false, null, $opts))->toBeTrue();
});

it('rejects select and multiselect without options', function () {
    (new CustomFieldRules)->toLaravel(CustomFieldType::Select, [], true, []);
})->throws(InvalidArgumentException::class);

it('rejects unknown rule names', function () {
    $r = new CustomFieldRules;

    expect($r->validateDefinition(CustomFieldType::Text, ['regex' => 'a']))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Boolean, ['min' => 1]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Textarea, ['pattern' => 'a']))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Date, ['min' => 1]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Text, ['min']))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Text, []))->toBe([]);
});

it('rejects min greater than max for every type with limits', function () {
    $r = new CustomFieldRules;

    expect($r->validateDefinition(CustomFieldType::Text, ['min' => 10, 'max' => 5]))->toContain('min must not exceed max')
        ->and($r->validateDefinition(CustomFieldType::Textarea, ['min' => 10, 'max' => 5]))->toContain('min must not exceed max')
        ->and($r->validateDefinition(CustomFieldType::Integer, ['min' => 10, 'max' => 5]))->toContain('min must not exceed max')
        ->and($r->validateDefinition(CustomFieldType::Date, ['date_min' => '2026-02-01', 'date_max' => '2026-01-01']))->toContain('date_min must not exceed date_max')
        ->and($r->validateDefinition(CustomFieldType::Text, ['min' => 5, 'max' => 5]))->toBe([]);
});

it('rejects out of range and non integer limits', function () {
    $r = new CustomFieldRules;

    expect($r->validateDefinition(CustomFieldType::Text, ['max' => 501]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Text, ['max' => 0]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Text, ['min' => -1]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Text, ['max' => '10']))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Text, ['max' => 10.0]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Text, ['max' => 500, 'min' => 0]))->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Textarea, ['max' => 20001]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Textarea, ['max' => 20000]))->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Integer, ['max' => '9223372036854775808']))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Integer, ['max' => 9223372036854775808]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Integer, ['min' => 1.5]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Integer, ['min' => PHP_INT_MIN, 'max' => PHP_INT_MAX]))->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Multiselect, ['max' => 51]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Multiselect, ['max' => 0]))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Multiselect, ['max' => 50]))->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Date, ['date_min' => '2026-02-30']))->not->toBe([])
        ->and($r->validateDefinition(CustomFieldType::Date, ['date_min' => '2026-2-1']))->not->toBe([]);
});

it('refuses to build rules from an invalid definition', function () {
    (new CustomFieldRules)->toLaravel(CustomFieldType::Text, ['pattern' => '(a+)+'], false, []);
})->throws(InvalidArgumentException::class);

it('rejects dangerous or malformed patterns', function (mixed $pattern) {
    $r = new CustomFieldRules;

    expect($r->validateDefinition(CustomFieldType::Text, ['pattern' => $pattern]))->not->toBe([])
        ->and($r->matches(is_string($pattern) ? $pattern : '', 'aaa'))->toBeNull();
})->with([
    'nested plus' => ['(a+)+'],
    'nested star' => ['(.*)*'],
    'class in nested star' => ['([a-z]+)*'],
    'overlapping alternation' => ['(a|aa)+'],
    'nested digits anchored' => ['(\d+)+$'],
    'huge repeat' => ['a{1,100000}'],
    'huge exact repeat' => ['a{1001}'],
    'nested brace quantifiers' => ['(a{1,}){2,}'],
    'nested bounded outer' => ['(a+){2,10}'],
    'non capturing nested' => ['(?:\w+\s?)*'],
    'unclosed class' => ['['],
    'unclosed group' => ['('],
    'stray close' => ['a)'],
    'anchor escape' => ['a)|(?:.*'],
    'delimiter injection e' => ['a/e'],
    'delimiter injection x' => ['abc/x'],
    'delimiter after group' => ['(?:a)$/e'],
    'slash in class' => ['[/]'],
    'backtracking verb' => ['(*FAIL)'],
    'verb with name' => ['(*MARK:x)a'],
    'recursion' => ['(?R)'],
    'numbered recursion' => ['(a)(?1)'],
    'g recursion' => ['(a)\g<1>'],
    'backreference' => ['(a)\1'],
    'callout' => ['(?C1)a'],
    'inline flags' => ['(?x)a #'],
    'quoted run' => ['\Qa'],
    'trailing backslash' => ['abc\\'],
    'too long' => [str_repeat('a', 201)],
    'empty' => [''],
    'nul byte' => ["a\0b"],
    'not a string' => [123],
    'invalid utf8' => ["\xff"],
]);

it('accepts ordinary patterns', function (string $pattern) {
    expect((new CustomFieldRules)->validateDefinition(CustomFieldType::Text, ['pattern' => $pattern]))->toBe([]);
})->with([
    ['[A-Z]{2}\d{4}'],
    ['\d{3}-\d{4}'],
    ['[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}'],
    ['https?:\/\/\S+'],
    ['(?:ab)?c'],
    ['\p{L}+'],
    [str_repeat('a', 200)],
]);

it('anchors patterns to the whole value', function () {
    $r = new CustomFieldRules;

    expect($r->matches('abc', 'abc'))->toBeTrue()
        ->and($r->matches('abc', 'xabcx'))->toBeFalse()
        ->and($r->matches('abc', 'abcx'))->toBeFalse()
        ->and($r->matches('abc', "abc\n"))->toBeFalse()
        ->and($r->matches('a|b', 'ab'))->toBeFalse()
        ->and($r->matches('a|b', 'b'))->toBeTrue()
        ->and($r->matches('abc', "\xff"))->toBeNull()
        ->and(customFieldPasses(CustomFieldType::Text, ['pattern' => 'abc'], true, 'xabcx'))->toBeFalse();
});

it('stops catastrophic backtracking quickly and reports invalid input', function () {
    $r = new CustomFieldRules;
    $pattern = 'a*a*a*a*a*a*b';
    expect($r->validateDefinition(CustomFieldType::Text, ['pattern' => $pattern]))->toBe([]);

    $before = ini_get('pcre.backtrack_limit');
    $start = microtime(true);
    $result = $r->matches($pattern, str_repeat('a', 500));
    $elapsed = microtime(true) - $start;

    expect($result)->not->toBeTrue()
        ->and($elapsed)->toBeLessThan(1.0)
        ->and(ini_get('pcre.backtrack_limit'))->toBe($before)
        ->and(customFieldPasses(CustomFieldType::Text, ['pattern' => $pattern], true, str_repeat('a', 500)))->toBeFalse();
});

it('exposes only the renderer schema keys', function () {
    $r = new CustomFieldRules;

    expect($r->toSchema(CustomFieldType::Text, ['min' => 2, 'pattern' => '[a-z]+'], true, ['ignored']))
        ->toBe(['required' => true, 'min' => 2, 'max' => 500, 'pattern' => '[a-z]+'])
        ->and($r->toSchema(CustomFieldType::Boolean, [], false, []))->toBe(['required' => false])
        ->and($r->toSchema(CustomFieldType::Integer, ['max' => 9], false, []))->toBe(['required' => false, 'max' => 9])
        ->and($r->toSchema(CustomFieldType::Date, ['date_min' => '2026-01-01'], false, []))->toBe(['required' => false, 'min' => '2026-01-01'])
        ->and($r->toSchema(CustomFieldType::Select, [], true, ['x', 'y']))->toBe(['required' => true, 'options' => ['x', 'y']])
        ->and($r->toSchema(CustomFieldType::Multiselect, ['max' => 3], false, ['x']))->toBe(['required' => false, 'max' => 3, 'options' => ['x']]);

    foreach (CustomFieldType::cases() as $type) {
        $keys = array_keys($r->toSchema($type, [], false, ['o']));
        expect(array_diff($keys, ['required', 'min', 'max', 'pattern', 'options']))->toBe([]);
    }
});

it('never exposes an invalid pattern in the schema', function () {
    (new CustomFieldRules)->toSchema(CustomFieldType::Text, ['pattern' => '(.*)*'], false, []);
})->throws(InvalidArgumentException::class);
