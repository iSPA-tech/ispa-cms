<?php

declare(strict_types=1);

use App\Support\Widget\WidgetInputSanitizer;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rs_setup(int $max = 12): array
{
    $widget = Widget::query()->create(['name' => 'Repeater widget']);
    $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'T', 'content' => '']);
    WidgetInput::query()->create([
        'widget_id' => $widget->id,
        'name' => 'feedback',
        'type' => 'repeater',
        'options' => ['max' => $max, 'fields' => [
            ['name' => 'name', 'type' => 'text'],
            ['name' => 'quote', 'type' => 'textarea'],
            ['name' => 'image', 'type' => 'image'],
        ]],
    ]);

    return [$widget->id, $theme->id];
}

function rs_run(array $values, int $max = 12): array
{
    [$w, $t] = rs_setup($max);

    return app(WidgetInputSanitizer::class)->sanitize($w, $t, $values);
}

it('sanitizes item fields by type', function () {
    $out = rs_run(['feedback' => [[
        '_id' => 'abc123',
        'name' => '<b>A & B</b>',
        'quote' => '<p onclick="x()">Hi</p><script>1</script>',
        'image' => ['url' => 'javascript:alert(1)', 'alt' => 'a'],
    ]]]);

    $item = $out['feedback'][0];
    expect($item['_id'])->toBe('abc123')
        ->and($item['name'])->toBe(e('<b>A & B</b>'))
        ->and($item['quote'])->not->toContain('onclick')->and($item['quote'])->not->toContain('<script>')
        ->and($item['quote'])->toContain('<p')
        ->and($item['image']['url'])->toBe('');
});

it('drops unknown keys and non-array items', function () {
    $out = rs_run(['feedback' => [['_id' => 'a1', 'name' => 'N', 'evil' => 'x'], 'str', 5, null]]);

    expect($out['feedback'])->toHaveCount(1)->and($out['feedback'][0])->not->toHaveKey('evil');
});

it('regenerates a bad _id', function () {
    $out = rs_run(['feedback' => [['_id' => '<x>', 'name' => 'N'], ['_id' => "a\n", 'name' => 'N']]]);

    expect($out['feedback'][0]['_id'])->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and($out['feedback'][1]['_id'])->toMatch('/^[A-Za-z0-9]{8}$/');
});

it('replaces duplicate _ids after the first', function () {
    $out = rs_run(['feedback' => [['_id' => 'dup', 'name' => 'A'], ['_id' => 'dup', 'name' => 'B']]]);

    expect($out['feedback'][0]['_id'])->toBe('dup')
        ->and($out['feedback'][1]['_id'])->not->toBe('dup')->toMatch('/^[A-Za-z0-9]{8}$/');
});

it('caps a tampered list at max', function () {
    $items = array_map(fn ($i) => ['_id' => 'i'.$i, 'name' => 'N'], range(1, 10));

    expect(rs_run(['feedback' => $items], 3)['feedback'])->toHaveCount(3);
});

it('clamps max to 100 and defaults to 12', function () {
    $items = array_map(fn ($i) => ['_id' => 'i'.$i, 'name' => 'N'], range(1, 150));

    expect(rs_run(['feedback' => $items], 5000)['feedback'])->toHaveCount(100);

    [$w, $t] = rs_setup();
    WidgetInput::query()->where('widget_id', $w)->update(['options' => json_encode(['fields' => [['name' => 'name', 'type' => 'text']]])]);
    expect(app(WidgetInputSanitizer::class)->sanitize($w, $t, ['feedback' => $items])['feedback'])->toHaveCount(12);
});

it('turns a non-array value into an empty list', function () {
    expect(rs_run(['feedback' => 'nope'])['feedback'])->toBe([]);
});
