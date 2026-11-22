<?php

use Cms\Core\Models\Permission;

test('Permission::key accessor concatenates group and name', function () {
    $model = new Permission([
        'group' => 'users',
        'name'  => 'create',
        'title' => 'Create Users',
    ]);

    expect($model->key)->toBe('users.create');
});

test('Permission::key is appended to array output', function () {
    $model = new Permission([
        'group' => 'posts',
        'name'  => 'edit',
        'title' => 'Edit Posts',
    ]);

    $array = $model->toArray();
    expect($array)->toHaveKey('key');
    expect($array['key'])->toBe('posts.edit');
});
