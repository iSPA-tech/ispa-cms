<?php

use Cms\Core\Admin\Resources\Condition;

$cases = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/resource-conditions.json'), true);

$dataset = [];
foreach ($cases as $case) {
    $dataset[$case['name']] = [$case['condition'], $case['values'], $case['expected']];
}

it('evaluates the shared fixture cases', function (array $condition, array $values, bool $expected) {
    expect(Condition::evaluate($condition, $values))->toBe($expected);
})->with($dataset);

it('has unique fixture names and at least 20 cases', function () use ($cases) {
    $names = array_column($cases, 'name');
    expect(count($names))->toBeGreaterThanOrEqual(20)
        ->and(count(array_unique($names)))->toBe(count($names));
});

it('throws on an unknown operator', function () {
    Condition::validate(['field' => 'a', 'op' => 'contains', 'value' => 'x']);
})->throws(InvalidArgumentException::class);

it('throws on malformed nodes', function (array $node) {
    expect(fn () => Condition::validate($node))->toThrow(InvalidArgumentException::class);
    expect(Condition::evaluate($node, ['a' => 'x']))->toBeFalse();
})->with([
    'leaf without field' => [['op' => 'eq', 'value' => 'x']],
    'leaf with non-string field' => [['field' => 5, 'op' => 'eq', 'value' => 'x']],
    'leaf without op' => [['field' => 'a']],
    'in without list' => [['field' => 'a', 'op' => 'in', 'value' => 'x']],
    'in without value' => [['field' => 'a', 'op' => 'in']],
    'eq without value key' => [['field' => 'a', 'op' => 'eq']],
    'group value not a list' => [['all' => 'x']],
    'group with non-array member' => [['any' => ['x']]],
    'group and leaf keys together' => [['all' => [], 'field' => 'a', 'op' => 'empty']],
    'both group keys' => [['all' => [], 'any' => []]],
    'neither leaf nor group' => [[]],
]);

it('accepts valid nodes', function () {
    Condition::validate(['all' => [['field' => 'a', 'op' => 'empty'], ['any' => []]]]);
    Condition::validate(['field' => 'a', 'op' => 'eq', 'value' => null]);
    expect(true)->toBeTrue();
});

it('builds JSON form with the eq and in helpers', function () {
    expect(Condition::eq('a', 'x'))->toBe(['field' => 'a', 'op' => 'eq', 'value' => 'x'])
        ->and(Condition::in('a', ['x', 'y']))->toBe(['field' => 'a', 'op' => 'in', 'value' => ['x', 'y']]);
});
