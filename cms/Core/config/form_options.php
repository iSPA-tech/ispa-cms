<?php

use App\Models\Province;
use App\Enums\StatusEnum;
use Cms\Core\Models\Category;
use Cms\Core\Models\Language;

$group = ['vi' => 'Dữ liệu chung', 'en' => 'Common data'];
return ['sources' => [
    'core.provinces' => [
        'public' => true, 'model' => Province::class, 'value' => 'uuid', 'label' => 'name',
        'title' => ['vi' => 'Tỉnh / Thành phố', 'en' => 'Provinces / Cities'], 'group' => $group,
        'where' => ['type' => [1, 2]],
    ],
    'core.province_names' => [
        'public' => true, 'model' => Province::class, 'value' => 'name', 'label' => 'name',
        'title' => ['vi' => 'Tên Tỉnh / Thành phố', 'en' => 'Province / City names'], 'group' => $group,
        'where' => ['type' => [1, 2]],
    ],
    'core.localities' => [
        'public' => true, 'model' => Province::class, 'value' => 'uuid', 'label' => 'name',
        'title' => ['vi' => 'Địa phương trực thuộc', 'en' => 'Local subdivisions'], 'group' => $group,
        'where' => ['type' => [3, 4]],
        'filters' => ['parent' => ['title' => ['vi' => 'Địa phương phía trên', 'en' => 'Parent locality'], 'type' => 'reference', 'source' => 'core.provinces', 'column' => 'parent_id']],
    ],
    'core.categories' => [
        'public' => true, 'model' => Category::class, 'value' => 'id', 'label' => 'name',
        'title' => ['vi' => 'Danh mục', 'en' => 'Categories'], 'group' => $group,
        'where' => ['status' => StatusEnum::PUBLISHED->value],
        'filters' => [
            'parent' => ['title' => ['vi' => 'Danh mục phía trên', 'en' => 'Parent category'], 'type' => 'reference', 'source' => 'core.categories', 'column' => 'parent_id'],
            'language' => ['title' => ['vi' => 'Ngôn ngữ', 'en' => 'Language'], 'type' => 'reference', 'source' => 'core.languages', 'column' => 'language_id'],
        ],
    ],
    'core.languages' => [
        'public' => true, 'model' => Language::class, 'value' => 'id', 'label' => 'name',
        'title' => ['vi' => 'Ngôn ngữ', 'en' => 'Languages'], 'group' => $group,
        'where' => ['status' => [StatusEnum::PUBLISHED->value, 'published'], 'for_web' => true],
    ],
]];
