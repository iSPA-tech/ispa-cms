<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\MetaValueBigNum;
use Cms\Core\Models\MetaValueBoolean;
use Cms\Core\Models\MetaValueDate;
use Cms\Core\Models\MetaValueString;
use Cms\Core\Models\MetaValueText;
use Cms\Core\Models\MetaValueTinyNum;

test('MetaValue casts', function () {
    expect((new MetaValueDate())->hasCast('value', 'datetime'))->toBeTrue();
    expect((new MetaValueBoolean())->hasCast('value', 'boolean'))->toBeTrue();
    expect((new MetaValueBigNum())->hasCast('value', 'integer'))->toBeTrue();
    expect((new MetaValueTinyNum())->hasCast('value', 'integer'))->toBeTrue();

    // Common user_id cast across value tables
    expect((new MetaValueString())->hasCast('user_id', 'integer'))->toBeTrue();
    expect((new MetaValueText())->hasCast('user_id', 'integer'))->toBeTrue();
    expect((new MetaValueDate())->hasCast('user_id', 'integer'))->toBeTrue();
    expect((new MetaValueBigNum())->hasCast('user_id', 'integer'))->toBeTrue();
    expect((new MetaValueTinyNum())->hasCast('user_id', 'integer'))->toBeTrue();
});

