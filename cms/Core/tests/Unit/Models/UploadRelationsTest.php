<?php

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Cms\Core\Models\Language;

beforeEach(function () {
    cache()->put('language-locale-vi', new Language([
        'id' => 1,
        'name' => 'Vietnamese',
        'path' => 'vi',
        'path_alias' => 'vi',
        'flag' => null,
        'currency' => 'VND',
    ]), 3600);
});
use Cms\Core\Models\Upload;

test('Upload relations return relation objects', function () {
    $model = new Upload();

    expect($model->pivots())->toBeInstanceOf(HasMany::class);
    expect($model->categories())->toBeInstanceOf(BelongsToMany::class);
    expect($model->categoryTarget())->toBeInstanceOf(HasMany::class);

    expect($model->targetUser())->toBeInstanceOf(BelongsToMany::class);
    // Declared type only: building the relation needs the optional Product module's model class.
    expect((new ReflectionMethod(Upload::class, 'targetProduct'))->getReturnType()?->getName())->toBe(BelongsToMany::class);
    expect($model->targetPost())->toBeInstanceOf(BelongsToMany::class);
    expect($model->targetCategory())->toBeInstanceOf(BelongsToMany::class);

    expect($model->content())->toBeInstanceOf(MorphOne::class);
    expect($model->contents())->toBeInstanceOf(MorphMany::class);
    expect($model->uploader())->toBeInstanceOf(BelongsTo::class);
    expect($model->target())->toBeInstanceOf(MorphTo::class);
});
