<?php

use Cms\Content\Models\Post;
use Cms\Content\Services\Forms\PublicFormOptionQueries;

return [
    'sources' => [
        'content.posts' => [
            'public' => true,
            'model' => Post::class,
            'value' => 'uuid',
            'label' => 'name',
            'title' => ['vi' => 'Bài viết', 'en' => 'Posts'],
            'group' => ['vi' => 'Nội dung', 'en' => 'Content'],
            'query' => [PublicFormOptionQueries::class, 'posts'],
            'filters' => [
                'category' => [
                    'title' => ['vi' => 'Danh mục', 'en' => 'Category'],
                    'type' => 'reference',
                    'column' => 'category_id',
                    'source' => 'core.categories',
                ],
            ],
        ],
    ],
];
