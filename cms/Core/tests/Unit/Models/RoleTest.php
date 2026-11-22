<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cms\Core\Models\Role;

test('Role users and permissions relations', function () {
    $model = new Role();
    expect($model->users())->toBeInstanceOf(BelongsToMany::class);
    expect($model->permissions())->toBeInstanceOf(BelongsToMany::class);
});
