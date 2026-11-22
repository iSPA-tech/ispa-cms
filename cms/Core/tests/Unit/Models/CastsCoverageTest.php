<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Category;
use Cms\Core\Models\Language;
use Cms\Core\Models\Menu;
use Cms\Core\Models\MenuItem;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\MetaData;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Upload;

test('common casts across core models', function () {
    // Category
    $category = new Category();
    expect($category->hasCast('featured', 'boolean'))->toBeTrue();
    expect($category->hasCast('hashtag', 'json'))->toBeTrue();
    expect($category->hasCast('content', 'json'))->toBeTrue();
    expect($category->hasCast('config', 'json'))->toBeTrue();

    // Language
    $lang = new Language();
    foreach (['default_web','default_adm','default_app','for_web','for_adm','for_app','is_locked'] as $flag) {
        expect($lang->hasCast($flag, 'boolean'))->toBeTrue();
    }

    // Menu
    expect((new Menu())->hasCast('language_id', 'integer'))->toBeTrue();

    // MenuItem
    expect((new MenuItem())->hasCast('custom', 'json'))->toBeTrue();

    // MetaAttribute
    $attr = new MetaAttribute();
    foreach (['is_filter','is_enabled','is_required','is_multiple'] as $flag) {
        expect($attr->hasCast($flag, 'boolean'))->toBeTrue();
    }

    // MetaClass
    $class = new MetaClass();
    foreach (['parent_id', 'category_id', 'user_id'] as $col) {
        expect($class->hasCast($col, 'integer'))->toBeTrue();
    }

    // MetaData
    $meta = new MetaData();
    foreach (['target_id','meta_class_id','meta_attribute_id','value_id'] as $col) {
        expect($meta->hasCast($col, 'integer'))->toBeTrue();
    }

    // Setting
    $setting = new Setting();
    expect($setting->hasCast('input_setting', 'array'))->toBeTrue(); // json -> array in 67db283a4
    expect($setting->hasCast('enumerate_id', 'integer'))->toBeTrue();
    expect($setting->hasCast('language_id', 'integer'))->toBeTrue();

    // Upload
    $upload = new Upload();
    expect($upload->hasCast('main', 'boolean'))->toBeTrue();
    foreach (['dimensions','custom','config'] as $jsonCol) {
        expect($upload->hasCast($jsonCol, 'json'))->toBeTrue();
    }
    foreach (['target_id','user_id'] as $intCol) {
        expect($upload->hasCast($intCol, 'integer'))->toBeTrue();
    }
});
