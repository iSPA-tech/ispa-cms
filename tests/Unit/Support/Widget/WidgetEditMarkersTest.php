<?php

declare(strict_types=1);

use App\Support\Widget\WidgetEditMarkers;

function wem_wrap(string $name, ?string $value, string $type = 'text'): string
{
    return WidgetEditMarkers::wrapValues([$name => $value], [$name => $type])[$name];
}

it('wraps text and textarea, leaves image untouched', function () {
    $img = [['url' => 'u']];
    $out = WidgetEditMarkers::wrapValues(
        ['a' => 'X', 'b' => '<p>Y</p>', 'c' => $img],
        ['a' => 'text', 'b' => 'textarea', 'c' => 'image'],
    );

    expect($out['a'])->toBe("\u{2063}F:a\u{2063}X\u{2063}/F\u{2063}")
        ->and($out['b'])->toBe("\u{2063}F:b\u{2063}<p>Y</p>\u{2063}/F\u{2063}")
        ->and($out['c'])->toBe($img);
});

it('strips pasted sentinel from values', function () {
    expect(wem_wrap('a', "A\u{2063}B"))->toBe("\u{2063}F:a\u{2063}AB\u{2063}/F\u{2063}");
});

it('wraps empty values so a placeholder is never empty', function () {
    expect(wem_wrap('x', ''))->toBe("\u{2063}F:x\u{2063}\u{2063}/F\u{2063}")
        ->and(wem_wrap('x', null))->toBe("\u{2063}F:x\u{2063}\u{2063}/F\u{2063}")
        ->and(wem_wrap('x', ''))->not->toBe('');
});

it('turns a text position marker into ispa-field', function () {
    $html = WidgetEditMarkers::finalize('<h2>' . wem_wrap('a', 'Hi') . '</h2>', ['a' => 'text']);

    expect($html)->toContain('<h2><ispa-field data-field="a" data-type="text">Hi</ispa-field></h2>')
        ->and($html)->toContain('"on_preview":["a"]')
        ->and($html)->not->toContain("\u{2063}");
});

it('strips markers in attribute position', function () {
    $html = WidgetEditMarkers::finalize('<img alt="' . wem_wrap('a', 'Hi') . '">', ['a' => 'text']);

    expect($html)->toContain('<img alt="Hi">')
        ->and($html)->not->toContain("\u{2063}")
        ->and($html)->not->toContain('<ispa-field')
        ->and($html)->toContain('"on_preview":[]')
        ->and($html)->toContain('"off_preview":["a"]');
});

it('keeps attribute clean and lists the field on preview when used in both places', function () {
    $w = wem_wrap('a', 'Hi');
    $html = WidgetEditMarkers::finalize('<a title="' . $w . '">' . $w . '</a>', ['a' => 'text']);

    expect($html)->toContain('<a title="Hi"><ispa-field data-field="a" data-type="text">Hi</ispa-field></a>')
        ->and($html)->toContain('"on_preview":["a"]')
        ->and($html)->toContain('"off_preview":[]')
        ->and($html)->not->toContain("\u{2063}");
});

it('never creates ispa-field inside script, style, title, textarea or comments', function (string $tpl) {
    $html = WidgetEditMarkers::finalize(sprintf($tpl, wem_wrap('a', 'Hi')), ['a' => 'text']);

    expect($html)->not->toContain('<ispa-field')
        ->and($html)->not->toContain("\u{2063}")
        ->and($html)->toContain('Hi')
        ->and($html)->toContain('"off_preview":["a"]');
})->with([
    'script' => ['<script>var x = "%s";</script>'],
    'style' => ['<style>.a:after{content:"%s"}</style>'],
    'title' => ['<title>%s</title>'],
    'textarea' => ['<textarea>%s</textarea>'],
    'comment' => ['<!-- %s -->'],
]);

it('resumes text position after a raw-text element', function () {
    $w = wem_wrap('a', 'Hi');
    $html = WidgetEditMarkers::finalize('<script>' . $w . '</script><p>' . $w . '</p>', ['a' => 'text']);

    expect($html)->toContain('<p><ispa-field data-field="a" data-type="text">Hi</ispa-field></p>')
        ->and(substr_count($html, '<ispa-field'))->toBe(1);
});

it('gives a textarea field data-type textarea and keeps inner html', function () {
    $html = WidgetEditMarkers::finalize(
        '<div>' . wem_wrap('b', '<p>Chúng tôi &amp; bạn</p>', 'textarea') . '</div>',
        ['b' => 'textarea'],
    );

    expect($html)->toContain('<ispa-field data-field="b" data-type="textarea"><p>Chúng tôi &amp; bạn</p></ispa-field>');
});

it('lists off_preview in types order and counts rendered images as on_preview', function () {
    $img = WidgetEditMarkers::markImage('<img src="/x.jpg" alt="">', 'pic');
    $html = WidgetEditMarkers::finalize(
        '<div>' . wem_wrap('t', 'T') . $img . '</div>',
        ['z' => 'text', 't' => 'text', 'pic' => 'image', 'y' => 'text', 'gone' => 'image'],
    );

    expect($img)->toBe('<img data-field="pic" data-type="image" src="/x.jpg" alt="">')
        ->and($html)->toContain('"on_preview":["t","pic"]')
        ->and($html)->toContain('"off_preview":["z","y","gone"]');
});

it('builds an empty image placeholder', function () {
    expect(WidgetEditMarkers::emptyImage('pic', 'w-full'))
        ->toBe('<ispa-field data-field="pic" data-type="image" data-empty="1" class="w-full"></ispa-field>');
});

it('handles dotted item field names in the start token', function () {
    $html = WidgetEditMarkers::finalize(
        '<p>' . wem_wrap('feedback.ab12.quote', '<em>Q</em>', 'textarea') . '</p><i title="' . wem_wrap('feedback.ab12.name', 'N') . '"></i>',
        ['feedback.ab12.quote' => 'textarea', 'feedback.ab12.name' => 'text'],
    );

    expect($html)->toContain('<p><ispa-field data-field="feedback.ab12.quote" data-type="textarea"><em>Q</em></ispa-field></p>')
        ->and($html)->toContain('<i title="N"></i>')
        ->and($html)->toContain('"on_preview":["feedback.ab12.quote"]')
        ->and($html)->toContain('"off_preview":["feedback.ab12.name"]')
        ->and($html)->not->toContain("\u{2063}");
});

it('adds repeaters to the manifest, lists the repeater on preview and keeps item fields out of both lists', function () {
    $html = WidgetEditMarkers::finalize(
        '<div>' . wem_wrap('feedback.ab12.name', 'N') . '</div>',
        ['feedback' => 'repeater', 'feedback.ab12.name' => 'text', 'feedback.ab12.role' => 'text', 'title' => 'text'],
        ['feedback' => ['count' => 1, 'max' => 12, 'item_label' => null, 'fields' => [['name' => 'name', 'type' => 'text']]]],
    );

    expect($html)->toContain('"on_preview":["feedback"]')
        ->and($html)->toContain('"off_preview":["title"]')
        ->and($html)->toContain('"repeaters":{"feedback":{"count":1,"max":12,"item_label":"mục","fields":[{"name":"name","type":"text"}]}}');
});

it('keeps the manifest without repeaters unchanged', function () {
    $html = WidgetEditMarkers::finalize('<h2>' . wem_wrap('a', 'Hi') . '</h2>', ['a' => 'text']);

    expect($html)->toEndWith('<script type="application/json" id="ispa-edit-fields">{"on_preview":["a"],"off_preview":[]}</script>');
});
