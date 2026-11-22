<?php

declare(strict_types=1);

use App\Support\Widget\WidgetRepeater;

function wr_defs(int $max = 6): array
{
    return [
        'feedback' => ['type' => 'repeater', 'options' => [
            'fields' => [
                ['name' => 'quote', 'type' => 'text', 'label' => 'Quote'],
                ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['name' => 'image', 'type' => 'image', 'label' => 'Image'],
            ],
            'max' => $max,
            'item_label' => 'card',
        ]],
        'title' => ['type' => 'text'],
    ];
}

function wr_tpl(string $inner): string
{
    return 'A{repeat:feedback template=`'.$inner.'`}B';
}

it('expands items in order with flat placeholders', function () {
    $r = WidgetRepeater::expand(
        wr_tpl('<div>{feedback.name}</div>'),
        ['title' => 'T', 'feedback' => [
            ['_id' => 'a', 'quote' => 'Q1', 'name' => 'N1'],
            ['_id' => 'b', 'quote' => 'Q2', 'name' => 'N2'],
        ]],
        wr_defs(),
        false
    );

    expect($r['content'])->toBe('A<div>{feedback.a.name}</div><div>{feedback.b.name}</div>B')
        ->and($r['values']['feedback.a.name'])->toBe('N1')
        ->and($r['values']['feedback.b.name'])->toBe('N2')
        ->and($r['values']['title'])->toBe('T')
        ->and($r['values'])->not->toHaveKey('feedback')
        ->and($r['types']['feedback.a.name'])->toBe('text')
        ->and($r['repeaters']['feedback']['count'])->toBe(2)
        ->and($r['repeaters']['feedback']['item_label'])->toBe('card');
});

it('missing field binds empty string; image binds empty array', function () {
    $r = WidgetRepeater::expand(wr_tpl('<p>{feedback.quote}</p>'), ['feedback' => [['_id' => 'a', 'name' => 'N']]], wr_defs(), false);

    expect($r['values']['feedback.a.quote'])->toBe('')
        ->and($r['values']['feedback.a.image'])->toBe([])
        ->and($r['types']['feedback.a.image'])->toBe('image');
});

it('undeclared field token becomes empty', function () {
    $r = WidgetRepeater::expand(wr_tpl('<p>{feedback.nope}|{feedback.name}</p>'), ['feedback' => [['_id' => 'a', 'name' => 'N']]], wr_defs(), false);

    expect($r['content'])->toBe('A<p>|{feedback.a.name}</p>B');
});

it('non-array value and non-array items are ignored', function () {
    $a = WidgetRepeater::expand(wr_tpl('<p>{feedback.name}</p>'), ['feedback' => 'x'], wr_defs(), true);
    $b = WidgetRepeater::expand(wr_tpl('<p>{feedback.name}</p>'), ['feedback' => ['x', null, ['_id' => 'a', 'name' => 'N']]], wr_defs(), false);

    expect($a['content'])->toBe('AB')
        ->and($b['content'])->toBe('A<p>{feedback.a.name}</p>B');
});

it('caps at max', function () {
    $items = [['_id' => 'a', 'name' => '1'], ['_id' => 'b', 'name' => '2'], ['_id' => 'c', 'name' => '3']];
    $r = WidgetRepeater::expand(wr_tpl('<i>{feedback.name}</i>'), ['feedback' => $items], wr_defs(2), false);

    expect($r['content'])->toBe('A<i>{feedback.a.name}</i><i>{feedback.b.name}</i>B')
        ->and($r['values'])->not->toHaveKey('feedback.c.name');
});

it('public mode skips all-empty items; edit mode keeps them', function () {
    $items = [['_id' => 'a', 'name' => 'N'], ['_id' => 'b', 'quote' => '', 'image' => []]];

    $public = WidgetRepeater::expand(wr_tpl('<i>{feedback.name}</i>'), ['feedback' => $items], wr_defs(), false);
    $edit = WidgetRepeater::expand(wr_tpl('<i>{feedback.name}</i>'), ['feedback' => $items], wr_defs(), true);

    expect($public['content'])->toBe('A<i>{feedback.a.name}</i>B')
        ->and($edit['content'])->toContain('{feedback.b.name}');
});

it('position token counts visible items', function () {
    $items = [['_id' => 'a', 'name' => 'N'], ['_id' => 'b'], ['_id' => 'c', 'name' => 'M']];
    $r = WidgetRepeater::expand(wr_tpl('<i>{feedback.#}</i>'), ['feedback' => $items], wr_defs(), false);

    expect($r['content'])->toBe('A<i>{feedback.a.#}</i><i>{feedback.c.#}</i>B')
        ->and($r['values']['feedback.a.#'])->toBe('1')
        ->and($r['values']['feedback.c.#'])->toBe('2')
        ->and($r['types']['feedback.a.#'])->toBe('text');
});

it('edit mode tags the item root', function () {
    $r = WidgetRepeater::expand(wr_tpl('<div class="c"><span>{feedback.name}</span></div>'), ['feedback' => [['_id' => 'a', 'name' => 'N']]], wr_defs(), true);

    expect($r['content'])->toBe('A<div data-repeat="feedback" data-item-id="a" data-item-index="1" class="c"><span>{feedback.a.name}</span></div>B');
});

it('no repeat token returns content and values unchanged', function () {
    $values = ['title' => 'T', 'feedback' => [['_id' => 'a']]];
    $r = WidgetRepeater::expand('<h1>{title}</h1>', $values, wr_defs(), false);

    expect($r['content'])->toBe('<h1>{title}</h1>')
        ->and($r['values'])->toBe($values)
        ->and($r['types'])->toBe([])
        ->and($r['repeaters'])->toBe([]);
});

it('image token inside item is renamed', function () {
    $r = WidgetRepeater::expand(
        wr_tpl('<div>{image name="feedback.image" class="x"}{upload name="feedback.image"}</div>'),
        ['feedback' => [['_id' => 'a', 'name' => 'N']]],
        wr_defs(),
        false
    );

    expect($r['content'])->toBe('A<div>{image name="feedback.a.image" class="x"}{upload name="feedback.a.image"}</div>B');
});

it('non-repeater input token becomes empty and keeps the value', function () {
    $r = WidgetRepeater::expand('A{repeat:title template=`<i>x</i>`}B', ['title' => 'T'], wr_defs(), false);

    expect($r['content'])->toBe('AB')->and($r['values'])->toBe(['title' => 'T']);
});

it('falls back to a positional id when _id is invalid', function () {
    $r = WidgetRepeater::expand(wr_tpl('<i>{feedback.name}</i>'), ['feedback' => [['_id' => 'bad id!', 'name' => 'N']]], wr_defs(), false);

    expect($r['content'])->toBe('A<i>{feedback.i1.name}</i>B');
});

it('rejects an _id with a trailing newline', function () {
    $r = WidgetRepeater::expand(wr_tpl('<i>{feedback.name}</i>'), ['feedback' => [['_id' => "a\n", 'name' => 'N']]], wr_defs(), false);

    expect($r['content'])->toBe('A<i>{feedback.i1.name}</i>B');
});
