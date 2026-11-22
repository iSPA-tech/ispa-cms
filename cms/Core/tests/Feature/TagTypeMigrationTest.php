<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Tag;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// 2026_11_07_200001: user tags move into tags (type user) + tags_data, the old tables go away.
uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Load the migration under test. */
function tagTypeMigration(): object
{
    return require base_path('cms/Core/database/migrations/2026_11_07_200001_add_type_to_tags_and_drop_user_tags.php');
}

/** Put the schema back to the pre-migration shape (no type / uuid / source, global unique slug). */
function tagTypeRewind(): void
{
    tagTypeMigration()->down();
    Schema::table('tags', static fn (Blueprint $t) => $t->dropColumn('uuid'));
    Schema::table('tags', static fn (Blueprint $t) => $t->unique('slug'));
}

test('moves user_tags and the pivot into tags (type user) and tags_data, then drops the old tables', function () {
    tagTypeRewind();
    Schema::create('user_tags', static function (Blueprint $t): void {
        $t->id(); $t->uuid('uuid')->unique(); $t->string('name', 120); $t->string('slug', 120)->unique(); $t->timestamps();
    });
    Schema::create('user_tag_user', static function (Blueprint $t): void {
        $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('tag_id'); $t->string('source', 16)->default('manual'); $t->timestamp('created_at')->useCurrent();
    });
    $postTagId = DB::table('tags')->insertGetId(['name' => 'Xe', 'slug' => 'xe', 'created_at' => now(), 'updated_at' => now()]);
    $uuid = (string) Str::uuid();
    DB::table('user_tags')->insert(['id' => 7, 'uuid' => $uuid, 'name' => 'Triết', 'slug' => 'xe', 'created_at' => now(), 'updated_at' => now()]); // same slug as the post tag
    DB::table('user_tag_user')->insert([['user_id' => 11, 'tag_id' => 7, 'source' => 'sync'], ['user_id' => 12, 'tag_id' => 7, 'source' => 'manual']]);

    tagTypeMigration()->up();

    expect(Schema::hasTable('user_tags'))->toBeFalse()->and(Schema::hasTable('user_tag_user'))->toBeFalse();
    $userTag = Tag::query()->ofType('user')->firstOrFail();
    expect($userTag->uuid)->toBe($uuid)->and($userTag->name)->toBe('Triết')->and($userTag->slug)->toBe('xe');
    $pairs = DB::table('tags_data')->where('tag_id', $userTag->id)->orderBy('target_id')->get();
    expect($pairs->pluck('target_id')->all())->toBe([11, 12])->and($pairs->pluck('source')->all())->toBe(['sync', 'manual'])
        ->and($pairs->pluck('target_type')->unique()->all())->toBe([User::class]);
    $post = Tag::query()->findOrFail($postTagId);
    expect($post->type)->toBe('post')->and($post->uuid)->toBeString()->not->toBeEmpty();
    expect(Tag::query()->ofType(Tag::TYPE_POST)->pluck("id")->all())->toBe([$postTagId]); // post tags are told apart from user tags by type
});

test('is idempotent and harmless when the old tables never existed', function () {
    DB::table('tags')->insert(['name' => 'Xe', 'slug' => 'xe', 'type' => 'post', 'uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    tagTypeMigration()->up();
    tagTypeMigration()->up();
    expect(Tag::query()->count())->toBe(1)->and(Schema::hasColumns('tags', ['type', 'uuid']))->toBeTrue()->and(Schema::hasColumn('tags_data', 'source'))->toBeTrue();
});

test('slug is unique per type, not globally', function () {
    Tag::query()->create(['name' => 'A', 'slug' => 'a']);
    Tag::query()->create(['name' => 'A', 'slug' => 'a', 'type' => 'user']);
    expect(fn () => Tag::query()->create(['name' => 'A2', 'slug' => 'a']))->toThrow(\Illuminate\Database\QueryException::class);
});
