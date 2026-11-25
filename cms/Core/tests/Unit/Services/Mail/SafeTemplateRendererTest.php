<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\Mail\SafeTemplateRenderer;

/**
 * Stored (admin-edited) mail templates are filled, never compiled: no Blade directive, raw echo or expression in the
 * template may run on the server.
 */
beforeEach(function () {
    $this->marker = storage_path('framework/testing/safe-template-renderer-' . getmypid() . '.txt');
    @unlink($this->marker);
});

afterEach(function () {
    @unlink($this->marker);
});

test('fills bare and dollar placeholders and escapes every value', function () {
    $html = (new SafeTemplateRenderer())->render(
        '<p>{{ name }} / {{$name}} / {{ order_code }}</p>',
        ['name' => 'A & <b>B</b>', 'order_code' => 'DH-1'],
    );

    expect($html)->toBe('<p>A &amp; &lt;b&gt;B&lt;/b&gt; / A &amp; &lt;b&gt;B&lt;/b&gt; / DH-1</p>');
});

test('prints server-built HTML raw only for keys passed as trusted', function () {
    $renderer = new SafeTemplateRenderer();
    $data = ['items' => '<tr><td>x</td></tr>', 'note' => '<tr><td>x</td></tr>'];

    expect($renderer->render('{{ items }}|{{ note }}', $data, ['items']))
        ->toBe('<tr><td>x</td></tr>|&lt;tr&gt;&lt;td&gt;x&lt;/td&gt;&lt;/tr&gt;');
});

test('leaves unknown placeholders and expressions empty', function () {
    $html = (new SafeTemplateRenderer())->render(
        'a{{ missing }}b{{ strtoupper($name) }}c{{ $name->value }}d{{ 1 + 1 }}e',
        ['name' => 'x'],
    );

    expect($html)->toBe('abcde');
});

test('a stored template cannot run php, include views or print raw input', function () {
    $marker = $this->marker;
    $template = implode("\n", [
        '@php file_put_contents(' . var_export($marker, true) . ', "owned"); @endphp',
        '<?php file_put_contents(' . var_export($marker, true) . ', "owned"); ?>',
        '{{ phpinfo() }}',
        '{{ file_put_contents(' . var_export($marker, true) . ', "owned") }}',
        '{!! $x !!}',
        '{!! system("id") !!}',
        "@include('mail.branded')",
        '@if(true) yes @endif',
    ]);

    ob_start();
    $html = (new SafeTemplateRenderer())->render($template, ['x' => '<script>alert(1)</script>']);
    $printed = ob_get_clean();

    expect($printed)->toBe('')
        ->and(file_exists($marker))->toBeFalse()
        ->and($html)->not->toContain('PHP Version')
        ->and($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        // Directives and PHP tags stay inert literal text.
        ->and($html)->toContain("@include('mail.branded')")
        ->and($html)->toContain('@php file_put_contents(');
});

test('keeps Blade-style escaped braces literal and drops template comments', function () {
    $html = (new SafeTemplateRenderer())->render('@{{ name }} {{-- note {{ name }} --}}{{ name }}', ['name' => 'Ann']);

    expect($html)->toBe('{{ name }} Ann');
});

test('renders plain text with both brace styles and no HTML escaping', function () {
    $text = (new SafeTemplateRenderer())->renderText(
        'Order {order_code} for {{ customer }} {missing}{{ phpinfo() }}',
        ['order_code' => 'DH-1', 'customer' => 'Tom & Jerry'],
    );

    expect($text)->toBe('Order DH-1 for Tom & Jerry ');
});

test('scalar, null, bool, enum and stringable values print like Blade echoes; arrays print nothing', function () {
    $html = (new SafeTemplateRenderer())->render(
        '[{{ a }}][{{ b }}][{{ c }}][{{ d }}][{{ e }}][{{ f }}]',
        [
            'a' => 12.5,
            'b' => null,
            'c' => true,
            'd' => ['x' => 1],
            'e' => new class implements Stringable {
                public function __toString(): string
                {
                    return '<i>s</i>';
                }
            },
            'f' => false,
        ],
    );

    expect($html)->toBe('[12.5][][1][][&lt;i&gt;s&lt;/i&gt;][]');
});
