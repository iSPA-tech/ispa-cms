<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Translate;

test('Translate casts', function () {
    $m = new Translate();
    expect($m->hasCast('text', 'json'))->toBeTrue();
    expect($m->hasCast('for_web', 'boolean'))->toBeTrue();
    expect($m->hasCast('for_adm', 'boolean'))->toBeTrue();
    expect($m->hasCast('for_app', 'boolean'))->toBeTrue();
});
