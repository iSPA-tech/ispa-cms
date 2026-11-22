<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\MetaAttribute;

test('MetaAttribute translated_label accessor returns translated label', function () {
    $m = new MetaAttribute(['label' => 'core.attribute.example']);
    // Fallback: when key is not present, __ returns the key itself
    expect($m->translated_label)->toBe('core.attribute.example');
});

