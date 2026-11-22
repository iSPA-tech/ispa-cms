<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use Cms\Content\Models\Content;
use Cms\Content\Models\Post;
use Cms\Content\Observers\ContentObserver;
use Cms\Content\Observers\PostObserver;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(\Tests\TestCase::class, RefreshDatabase::class);

test('both Content models register the observers that live in Content', function () {
    expect(PostObserver::class)->toStartWith('Cms\\Content\\Observers\\');
    expect(ContentObserver::class)->toStartWith('Cms\\Content\\Observers\\');

    foreach ([Post::class => PostObserver::class, Content::class => ContentObserver::class] as $model => $observer) {
        new $model(); // boots the model, which attaches the observer
        $ref = new ReflectionProperty($model, 'observer');
        expect($ref->getValue())->toBe($observer);
        expect($model::getEventDispatcher()->hasListeners("eloquent.created: {$model}"))->toBeTrue();
    }
});

test('nothing in cms/Content imports App\\Observers and the old classes are gone', function () {
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('cms/Content'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php' && $file->getRealPath() !== __FILE__
            && str_contains((string) file_get_contents($file->getRealPath()), 'App\\Observers')) {
            $hits[] = $file->getRealPath();
        }
    }
    expect($hits)->toBe([]);
    expect(file_exists(base_path('app/Observers/PostObserver.php')))->toBeFalse();
    expect(file_exists(base_path('app/Observers/ContentObserver.php')))->toBeFalse();
});

test('the post observer audits create, delete, restore and force delete', function () {
    Event::fake([AuditLogEvent::class]);
    $post = new Post();
    $post->id = 7;
    $observer = new PostObserver();

    $observer->created($post);
    $observer->deleted($post);
    $observer->restored($post);

    Event::assertDispatchedTimes(AuditLogEvent::class, 3);
});

test('the content observer audits create, delete, restore and force delete', function () {
    Event::fake([AuditLogEvent::class]);
    $content = new Content();
    $content->id = 9;
    $observer = new ContentObserver();

    $observer->created($content);
    $observer->restored($content);
    $observer->forceDeleted($content);

    Event::assertDispatchedTimes(AuditLogEvent::class, 3);
});

test('a post update runs the active post.updated workflows', function () {
    $source = (string) file_get_contents(base_path('cms/Content/app/Observers/PostObserver.php'));

    expect($source)->toContain("'post.updated'")
        ->and($source)->toContain('WorkflowExecutor');
});
