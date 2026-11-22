<?php

declare(strict_types=1);

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Content\Models\Post;
use Cms\Content\Services\ContentAttentionProvider;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // posts.status references the enumerates table, so the status keys must exist there.
    foreach (['status_pending', 'status_published'] as $key) {
        DB::table(CoreTableConstant::ENUMERATES)->insertOrIgnore(['name' => $key, 'enum_key' => $key, 'type' => 'status', 'created_at' => now(), 'updated_at' => now()]);
    }
});

/**
 * Create a user holding the given permissions (group.name keys) through a role.
 *
 * @param array<int, string> $keys
 */
function contentAttentionUser(array $keys): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(), 'name' => 'Editor', 'email' => Str::random(6) . '@example.test',
        'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($keys !== []) {
        $roleId = DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Role ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($keys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permissionId = DB::table(CoreTableConstant::PERMISSIONS)->insertGetId([
                'group' => $group, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table(CoreTableConstant::PERMISSION_ROLE)->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $id]);
    }

    return User::query()->findOrFail($id);
}

/**
 * Insert posts with the given statuses (null = no status), optionally soft deleted.
 */
function contentAttentionPosts(string $status, int $count, bool $deleted = false, string $name = 'Post'): void
{
    for ($i = 1; $i <= $count; $i++) {
        $post = Post::withoutEvents(static fn (): Post => Post::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name . ' ' . $i, 'type' => 'post', 'status' => null,
        ]));
        DB::table('posts')->where('id', $post->id)->update([
            'status' => $status,
            'created_at' => sprintf('2026-10-%02d 08:00:00', $i),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}

/**
 * Insert comments with the given status, optionally soft deleted.
 */
function contentAttentionComments(string $status, int $count, bool $deleted = false): void
{
    for ($i = 1; $i <= $count; $i++) {
        DB::table('comments')->insert([
            'name' => 'Visitor ' . $i, 'content' => 'Hello', 'status' => $status,
            'created_at' => sprintf('2026-10-%02d 08:00:00', $i), 'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}

/**
 * A registry with the Content provider registered, like the module boot does.
 */
function contentAttentionRegistry(): AttentionRegistry
{
    $registry = new AttentionRegistry();
    ContentAttentionProvider::register($registry);

    return $registry;
}

it('shows posts_pending to a user with post.viewAny only', function (): void {
    contentAttentionPosts('status_pending', 3);
    contentAttentionPosts('status_published', 2);
    contentAttentionPosts('status_pending', 4, true);
    contentAttentionComments('status_pending', 2);

    $entries = contentAttentionRegistry()->forUser(contentAttentionUser(['post.viewAny']));

    expect($entries)->toBe([
        ['key' => 'posts_pending', 'count' => 3, 'route' => 'post-post', 'query' => ['status' => 'status_pending'], 'tone' => 'info'],
    ]);
});

it('shows comments_pending to a user with comment.viewAny only', function (): void {
    contentAttentionComments('status_pending', 2);
    contentAttentionComments('status_published', 5);
    contentAttentionComments('status_pending', 3, true);
    contentAttentionPosts('status_pending', 1);

    $entries = contentAttentionRegistry()->forUser(contentAttentionUser(['comment.viewAny']));

    expect($entries)->toBe([
        ['key' => 'comments_pending', 'count' => 2, 'route' => 'post-comment', 'query' => ['status' => 'status_pending'], 'tone' => 'info'],
    ]);
});

it('shows nothing to a user without either permission', function (): void {
    contentAttentionPosts('status_pending', 1);
    contentAttentionComments('status_pending', 1);

    expect(contentAttentionRegistry()->forUser(contentAttentionUser([])))->toBe([]);
});

it('hides entries whose count is zero', function (): void {
    contentAttentionPosts('status_published', 2);
    contentAttentionComments('status_published', 2);

    expect(contentAttentionRegistry()->forUser(contentAttentionUser(['post.viewAny', 'comment.viewAny'])))->toBe([]);
});

it('gives a user holding both permissions comments first, then posts', function (): void {
    contentAttentionPosts('status_pending', 1);
    contentAttentionComments('status_pending', 1);

    $keys = array_column(contentAttentionRegistry()->forUser(contentAttentionUser(['post.viewAny', 'comment.viewAny'])), 'key');

    expect($keys)->toBe(['comments_pending', 'posts_pending']);
});

it('does not leak rows between users with different permissions on one registry', function (): void {
    contentAttentionPosts('status_pending', 1);
    contentAttentionComments('status_pending', 1);
    $registry = contentAttentionRegistry();

    $poster = contentAttentionUser(['post.viewAny']);
    $moderator = contentAttentionUser(['comment.viewAny']);

    expect(array_column($registry->forUser($poster), 'key'))->toBe(['posts_pending']);
    expect(array_column($registry->forUser($moderator), 'key'))->toBe(['comments_pending']);
    expect(array_column($registry->forUser($poster), 'key'))->toBe(['posts_pending']);
});

it('lists at most ten oldest pending posts first', function (): void {
    contentAttentionPosts('status_pending', 12, false, 'Pending');

    $entry = contentAttentionRegistry()->forUser(contentAttentionUser(['post.viewAny']), true)[0];

    expect($entry['count'])->toBe(12);
    expect($entry['items'])->toHaveCount(10);
    expect($entry['items'][0]['title'])->toBe('Pending 1');
    expect($entry['items'][0]['route'])->toBe('post-post');
    expect($entry['items'][0]['params'])->toBe([]);
    expect($entry['items'][0]['at'])->toBeString();
});

it('lists pending comments with the commenter name', function (): void {
    contentAttentionComments('status_pending', 2);

    $entry = contentAttentionRegistry()->forUser(contentAttentionUser(['comment.viewAny']), true)[0];

    expect($entry['items'][0]['title'])->toBe('Visitor 1');
    expect($entry['items'][0]['route'])->toBe('post-comment');
});

it('keeps the summary keys and carries each pending entry once for a permitted user', function (): void {
    contentAttentionPosts('status_pending', 3);
    contentAttentionComments('status_pending', 2);

    $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
    config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
    // A Webmaster holds every permission through its role, like after cms:modules-sync.
    $webmaster = contentAttentionUser(['post.viewAny', 'comment.viewAny']);
    DB::table('users')->where('id', $webmaster->id)->update(['type' => UserType::WEBMASTER]);
    Sanctum::actingAs(User::query()->findOrFail($webmaster->id));

    $response = $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')->getJson('/api/v2/admin/dashboard/summary?reload=1');
    $response->assertOk();
    $data = $response->json('data');

    foreach (['range', 'traffic', 'content', 'comments', 'commerce', 'people', 'attention', 'notices', 'series', 'modules'] as $key) {
        expect($data)->toHaveKey($key);
    }

    $keys = array_column($data['attention'], 'key');
    expect(array_count_values($keys)['comments_pending'] ?? 0)->toBe(1);
    expect(array_count_values($keys)['posts_pending'] ?? 0)->toBe(1);

    $posts = collect($data['attention'])->firstWhere('key', 'posts_pending');
    expect($posts)->toBe(['key' => 'posts_pending', 'count' => 3, 'route' => 'post-post', 'query' => ['status' => 'status_pending'], 'tone' => 'info']);
    $comments = collect($data['attention'])->firstWhere('key', 'comments_pending');
    expect($comments)->toBe(['key' => 'comments_pending', 'count' => 2, 'route' => 'post-comment', 'query' => ['status' => 'status_pending'], 'tone' => 'info']);
});

it('does not show comments_pending to a user holding only the old post.comment key', function (): void {
    contentAttentionComments('status_pending', 2);

    expect(contentAttentionRegistry()->forUser(contentAttentionUser(['post.comment'])))->toBe([]);
});

/**
 * Call the admin comment index as the given user.
 */
function contentAttentionCommentIndex(User $user): \Illuminate\Testing\TestResponse
{
    test()->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
    config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
    // The api.admin middleware only admits Admin/Webmaster accounts; the policy then decides on the permission key.
    DB::table('users')->where('id', $user->id)->update(['type' => UserType::ADMIN]);
    Sanctum::actingAs(User::query()->findOrFail($user->id));

    return test()->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')->getJson('/api/v2/admin/comment');
}

it('lets the same comment.viewAny key list comments through the admin API', function (): void {
    contentAttentionComments('status_pending', 2);
    $user = contentAttentionUser(['comment.viewAny']);

    expect(array_column(contentAttentionRegistry()->forUser($user), 'key'))->toBe(['comments_pending']);

    $response = contentAttentionCommentIndex($user);
    $response->assertOk();
    expect($response->json('message'))->not->toBe(__('mgs.no.permission'));
});

it('refuses the admin comment list to a user without comment.viewAny', function (): void {
    contentAttentionComments('status_pending', 2);

    $response = contentAttentionCommentIndex(contentAttentionUser(['post.comment']));

    expect($response->json('message'))->toBe(__('mgs.no.permission'));
});
