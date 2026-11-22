<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Generator\ModuleNames;

uses(Cms\Core\Tests\TestCase::class);

// cms:make-module renames the template's placeholders (module `Skeleton`, entity `Thing`) to the new names.

it('maps every placeholder form for multi-word names', function (): void {
    $tokens = (new ModuleNames('KnowledgeBase', 'PostCategory'))->tokens();

    expect($tokens)->toMatchArray([
        'SkeletonThings' => 'KnowledgeBasePostCategories',
        'SkeletonThing' => 'KnowledgeBasePostCategory',
        'skeletonThings' => 'knowledgeBasePostCategories',
        'skeletonThing' => 'knowledgeBasePostCategory',
        'skeleton_things' => 'knowledge_base_post_categories',
        'skeleton_thing' => 'knowledge_base_post_category',
        'skeleton-things' => 'knowledge-base-post-categories',
        'skeleton-thing' => 'knowledge-base-post-category',
        'SKELETON_THINGS' => 'KNOWLEDGE_BASE_POST_CATEGORIES',
        'SKELETON_THING' => 'KNOWLEDGE_BASE_POST_CATEGORY',
        'Skeleton' => 'KnowledgeBase',
        'skeleton' => 'knowledge-base',
        'SKELETON' => 'KNOWLEDGE_BASE',
        'Things' => 'PostCategories',
        'Thing' => 'PostCategory',
        'things' => 'post-categories',
        'thing' => 'post-category',
    ]);

    // Longest first, so a combined form is never cut by a shorter one.
    $lengths = array_map('strlen', array_keys($tokens));
    $sorted = $lengths;
    rsort($sorted);
    expect($lengths)->toBe($sorted);
});

it('uses irregular plurals', function (): void {
    expect((new ModuleNames('Blog', 'Category'))->tokens()['SkeletonThings'])->toBe('BlogCategories')
        ->and((new ModuleNames('Blog', 'Person'))->tokens()['things'])->toBe('people');
});

it('renames whole tokens only', function (): void {
    $names = new ModuleNames('Blog', 'Post');

    expect($names->rename('Something something SkeletonThingController QueueSkeletonThingJob skeleton_thing_id things.vue nav.a.skeleton-things Skeletons'))
        ->toBe('Something something BlogPostController QueueBlogPostJob blog_post_id posts.vue nav.a.blog-posts Skeletons')
        ->and($names->alias())->toBe('blog');
});

it('validates module and entity names', function (string $name, bool $valid): void {
    expect(ModuleNames::validate($name) === null)->toBe($valid);
})->with([
    ['Blog', true],
    ['KnowledgeBase2', true],
    ['blog', false],
    ['Blog-Post', false],
    ['2Blog', false],
    ['', false],
    ['Blog Post', false],
    // Runs of capitals turn into one-letter words (CMSBlog -> c-m-s-blog): write CmsBlog.
    ['CMSBlog', false],
    ['AIAssistant', false],
    ['SKELETON', false],
    ['CmsBlog', true],
    ['Blog2Go', true],
]);
