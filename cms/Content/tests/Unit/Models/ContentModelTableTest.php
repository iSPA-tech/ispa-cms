<?php

uses(\Tests\TestCase::class);

use Cms\Content\Constants\ContentTableConstant as Tables;

it('content models use expected tables', function (string $modelClass, string $table) {
    $model = new $modelClass();

    expect($model->getTable())->toBe($table);
})->with([
    [\Cms\Content\Models\Content::class, Tables::CONTENTS],
    [\Cms\Content\Models\Post::class, Tables::POSTS],
    [\Cms\Core\Models\Tag::class, Tables::TAGS],
    [\Cms\Core\Models\TagData::class, Tables::TAGS_DATA],
    [\Cms\Content\Models\BookMark::class, Tables::BOOKMARK],
]);
