<?php

namespace Tests\Unit\Models;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Upload;
use Cms\Core\Traits\Models\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

if (! class_exists(HasMediaTraitTestModel::class)) {
    class HasMediaTraitTestModel extends Model
    {
        use HasMediaTrait;

        protected $table = 'has_media_trait_test_models';

        protected $guarded = [];

        public $timestamps = false;
    }
}

beforeEach(function () {
    Schema::dropIfExists(CoreTableConstant::UPLOAD_TARGET_PIVOT);
    Schema::dropIfExists(CoreTableConstant::UPLOADS);
    Schema::dropIfExists('has_media_trait_test_models');

    Schema::create('has_media_trait_test_models', static function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create(CoreTableConstant::UPLOADS, static function (Blueprint $table) {
        $table->id();
        $table->string('disk')->nullable();
        $table->string('type')->nullable();
        $table->string('mime_type')->nullable();
        $table->string('path')->nullable();
        $table->string('name')->nullable();
        $table->string('name_alias')->nullable();
        $table->string('alt_text')->nullable();
        $table->integer('file_size')->default(0);
        $table->boolean('main')->default(false);
        $table->string('target_folder')->nullable();
        $table->string('target_type')->nullable();
        $table->unsignedBigInteger('target_id')->nullable();
        $table->string('category')->nullable();
        $table->json('dimensions')->nullable();
        $table->json('custom')->nullable();
        $table->json('config')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create(CoreTableConstant::UPLOAD_TARGET_PIVOT, static function (Blueprint $table) {
        $table->unsignedBigInteger('upload_id')->nullable();
        $table->unsignedBigInteger('target_id')->nullable();
        $table->string('target_type')->nullable();
        $table->string('category')->nullable();
        $table->boolean('main')->default(false);
        $table->unsignedMediumInteger('order')->default(0);
    });
});

test('sync images accepts uploaded model instances returned by image helper', function () {
    $model = HasMediaTraitTestModel::query()->create(['name' => 'Post']);
    $firstUpload = Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'path' => 'image/2026/05',
        'name' => 'thumbnail-1.jpg',
        'file_size' => 1000,
    ]);
    $secondUpload = Upload::query()->create([
        'disk' => 'public',
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'path' => 'image/2026/05',
        'name' => 'thumbnail-2.jpg',
        'file_size' => 1000,
    ]);

    $model->syncImages([$firstUpload, $secondUpload]);

    expect($model->images()->pluck(CoreTableConstant::UPLOADS . '.id')->all())->toBe([
        $firstUpload->id,
        $secondUpload->id,
    ]);
    expect($model->fresh()->image?->id)->toBe($firstUpload->id);
});
