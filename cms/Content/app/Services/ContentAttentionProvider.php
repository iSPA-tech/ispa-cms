<?php

declare(strict_types=1);

namespace Cms\Content\Services;

use Cms\Content\Constants\ContentTableConstant;
use Cms\Core\Constants\CoreTableConstant;
use App\Models\Comment;
use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Content\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feeds the "my tasks" list and the dashboard strip with the comments and posts still waiting for review
 * (status `status_pending`), the same rows the comments page and the posts page open with the pending filter.
 */
final class ContentAttentionProvider
{
    public const KEY_COMMENTS = 'comments_pending';

    public const KEY_POSTS = 'posts_pending';

    private const STATUS_PENDING = 'status_pending';

    private const ITEM_LIMIT = 10;

    /**
     * Register both providers: comments first, then posts. The posts ability is the `viewAny` policy check the post
     * list endpoint uses; the comments ability is the `viewAny` policy check (`comment.viewAny`) the comment list endpoint and the comments menu entry use.
     */
    public static function register(AttentionRegistry $registry): void
    {
        $provider = new self();

        $registry->register(
            self::KEY_COMMENTS,
            static fn (Authenticatable $user): bool => $user instanceof User && $user->can('viewAny', Comment::class),
            static fn (Authenticatable $user): int => $provider->commentCount($user),
            static fn (Authenticatable $user): array => $provider->commentItems($user),
            ['route' => 'post-comment', 'query' => ['status' => self::STATUS_PENDING], 'tone' => 'info']
        );

        $registry->register(
            self::KEY_POSTS,
            static fn (Authenticatable $user): bool => $user instanceof User && $user->can('viewAny', Post::class),
            static fn (Authenticatable $user): int => $provider->postCount($user),
            static fn (Authenticatable $user): array => $provider->postItems($user),
            ['route' => 'post-post', 'query' => ['status' => self::STATUS_PENDING], 'tone' => 'info']
        );
    }

    /**
     * Pending comments that are not deleted; 0 for a non-User or a missing table.
     */
    public function commentCount(Authenticatable $user): int
    {
        $query = $this->query(CoreTableConstant::COMMENTS, $user);

        return $query === null ? 0 : $query->count();
    }

    /**
     * Up to ten oldest pending comments; empty for a non-User or a missing table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function commentItems(Authenticatable $user): array
    {
        $query = $this->query(CoreTableConstant::COMMENTS, $user);
        if ($query === null) {
            return [];
        }

        return $query->orderBy('created_at')->orderBy('id')->limit(self::ITEM_LIMIT)->get()
            ->map(static fn (object $row): array => [
                'title' => (string) ($row->name ?: ($row->subject ?: '#' . $row->id)),
                'route' => 'post-comment',
                'params' => [],
                'at' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String() : null,
                'at_label' => 'text.my_tasks.at.created',
            ])
            ->values()
            ->all();
    }

    /**
     * Pending posts that are not deleted; 0 for a non-User or a missing table.
     */
    public function postCount(Authenticatable $user): int
    {
        $query = $this->query(ContentTableConstant::POSTS, $user);

        return $query === null ? 0 : $query->count();
    }

    /**
     * Up to ten oldest pending posts; empty for a non-User or a missing table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function postItems(Authenticatable $user): array
    {
        $query = $this->query(ContentTableConstant::POSTS, $user);
        if ($query === null) {
            return [];
        }

        return $query->orderBy('created_at')->orderBy('id')->limit(self::ITEM_LIMIT)->get()
            ->map(static fn (object $row): array => [
                'title' => (string) ($row->name ?: '#' . $row->id),
                'route' => 'post-post',
                'params' => [],
                'at' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String() : null,
                'at_label' => 'text.my_tasks.at.created',
            ])
            ->values()
            ->all();
    }

    /**
     * Pending, non-deleted rows of a table; null for a non-User or a missing table.
     */
    private function query(string $table, Authenticatable $user): ?Builder
    {
        if (! $user instanceof User || ! Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->whereNull('deleted_at')->where('status', self::STATUS_PENDING);
    }
}
