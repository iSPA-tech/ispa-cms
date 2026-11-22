<?php

uses(\Tests\TestCase::class);

use App\Enums\StatusEnum;
use Cms\Content\Http\Resources\Post\PostResource;
use Cms\Content\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

function makePostResourcePost(array $overrides = []): Post
{
    $post = new Post(array_merge([
        'id' => 10,
        'uuid' => 'post-10',
        'name' => 'Post resource date test',
        'type' => 'page',
        'status' => StatusEnum::PUBLISHED->value,
        'created_at' => Carbon::parse('2026-06-26 16:00:00', 'UTC'),
        'published_at' => null,
    ], $overrides));

    $post->setRelation('latestSlug', null);
    $post->setRelation('image', null);
    $post->setRelation('attachs', new Collection());

    return $post;
}

test('post resource preserves null published date', function () {
    $resource = (new PostResource(makePostResourcePost()))->toArray(Request::create('/'));

    expect($resource['published_at'])->toBeNull();
});

test('post resource formats published date in configured timezone', function () {
    config(['ispa-cms.timezone' => 'Asia/Ho_Chi_Minh']);

    $post = makePostResourcePost();
    $post->setRawAttributes(array_merge($post->getAttributes(), [
        'published_at' => '2026-06-26T16:23:25+00:00',
    ]), true);

    $resource = (new PostResource($post))->toArray(Request::create('/'));

    expect($resource['published_at'])->toBe('2026-06-26 23:23:25');
});
