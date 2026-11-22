<?php

use Cms\Core\Services\Forms\FormLayout;

uses(\Cms\Core\Tests\TestCase::class);

test('legacy forms remain single column and responsive spans inherit safely', function () {
    expect(FormLayout::columns([]))->toBe(['mobile' => 1, 'tablet' => 1, 'desktop' => 1]);
    $custom = ['layout' => ['columns' => ['mobile' => 1, 'tablet' => 2, 'desktop' => 3]], 'unknown' => ['keep' => true]];
    $before = $custom;
    $columns = FormLayout::columns($custom);
    expect(FormLayout::spans([], $columns, 'text'))->toBe(['mobile' => 1, 'tablet' => 1, 'desktop' => 1]);
    expect(FormLayout::spans([], $columns, 'textarea'))->toBe($columns);
    expect(FormLayout::spans(['layout' => ['span' => ['mobile' => 'full', 'tablet' => 3, 'desktop' => 2]]], $columns, 'text'))
        ->toBe(['mobile' => 1, 'tablet' => 2, 'desktop' => 2]);
    expect($custom)->toBe($before);
});

test('rendered layout never accepts arbitrary CSS or invalid counts', function () {
    $columns = FormLayout::columns(['layout' => ['columns' => ['mobile' => '2; background:url(x)', 'tablet' => -1, 'desktop' => []]]]);
    expect($columns)->toBe(['mobile' => 1, 'tablet' => 1, 'desktop' => 1]);
    expect(FormLayout::style($columns, 'cols'))->toBe('--form-cols-mobile:1;--form-cols-tablet:1;--form-cols-desktop:1');
});
