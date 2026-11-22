<?php

namespace Cms\Content\Services;

use Cms\Content\Constants\ContentTableConstant;
use Cms\Content\Models\Post;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Upload;
use Cms\Core\Services\OrphanUploadPurgeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purges soft-deleted posts.
 *
 * @api Content public API; signature changes follow the module notes.
 */
final class DeletedPostPurgeService
{
    public function __construct(private readonly OrphanUploadPurgeService $uploads)
    {
    }

    /**
     * @return array{apply: bool, days: int, candidate_count: int, purged: int, upload_purged: int, deferred_image_ids: list<int>}
     */
    public function purge(int $days = 30, int $limit = 500, bool $apply = false, ?Carbon $now = null, bool $deferImages = false): array
    {
        $days = max(1, $days);
        $limit = min(5000, max(1, $limit));
        $cutoff = ($now ?? now())->copy()->subDays($days);
        $posts = Post::onlyTrashed()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return ['days' => $days] + $this->purgePosts($posts, $apply, $deferImages);
    }

    /**
     * Permanently delete only the selected posts and any media left orphaned by them.
     *
     * @param list<int> $postIds
     * @return array{apply: bool, candidate_count: int, purged: int, upload_purged: int, deferred_image_ids: list<int>}
     */
    public function purgeSelected(array $postIds, bool $apply = false, bool $deferImages = false): array
    {
        $ids = collect($postIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $posts = $ids->isEmpty()
            ? collect()
            : Post::withTrashed()->whereIn('id', $ids)->orderBy('id')->get();

        return $this->purgePosts($posts, $apply, $deferImages);
    }

    /**
     * @param Collection<int, Post> $posts
     * @return array{apply: bool, candidate_count: int, purged: int, upload_purged: int, deferred_image_ids: list<int>}
     */
    private function purgePosts(Collection $posts, bool $apply, bool $deferImages): array
    {
        if (! $apply) {
            return [
                'apply' => false,
                'candidate_count' => $posts->count(),
                'purged' => 0,
                'upload_purged' => 0,
                'deferred_image_ids' => [],
            ];
        }

        $uploadPurged = 0;
        $deferredImageIds = [];
        foreach ($posts as $post) {
            $uploadIds = Schema::hasTable(CoreTableConstant::UPLOAD_TARGET_PIVOT)
                ? DB::table(CoreTableConstant::UPLOAD_TARGET_PIVOT)
                    ->where('target_type', Post::class)
                    ->where('target_id', $post->getKey())
                    ->pluck('upload_id')
                    ->filter()
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                : collect();
            if ($deferImages) {
                $uploadIds = $uploadIds->merge(Upload::withTrashed()
                    ->where('target_type', Post::class)
                    ->where('target_id', $post->getKey())
                    ->whereIn('type', ['image', 'avatar'])
                    ->pluck('id'))
                    ->unique();
            }

            DB::transaction(function () use ($post): void {
                foreach ([
                    CoreTableConstant::CATEGORY_TARGET,
                    ContentTableConstant::TAGS_DATA,
                    ContentTableConstant::CONTENTS,
                    CoreTableConstant::SLUGS,
                    CoreTableConstant::SEOS,
                    CoreTableConstant::UPLOAD_TARGET_PIVOT,
                    CoreTableConstant::AUDIT_LOGS,
                ] as $table) {
                    if (! Schema::hasTable($table)
                        || ! Schema::hasColumn($table, 'target_type')
                        || ! Schema::hasColumn($table, 'target_id')) {
                        continue;
                    }

                    if ($table === CoreTableConstant::AUDIT_LOGS) {
                        AuditLog::withTrashed()
                            ->where('target_type', Post::class)
                            ->where('target_id', $post->getKey())
                            ->forceDelete();

                        continue;
                    }

                    DB::table($table)
                        ->where('target_type', Post::class)
                        ->where('target_id', $post->getKey())
                        ->delete();
                }

                Post::withoutEvents(static fn (): ?bool => $post->forceDelete());
            });

            foreach ($uploadIds as $uploadId) {
                $upload = Upload::withTrashed()->find($uploadId);
                if ($upload && $deferImages && in_array($upload->type, ['image', 'avatar'], true)) {
                    $deferredImageIds[] = (int) $uploadId;
                    continue;
                }
                if ($upload && $this->uploads->purgeIfOrphan($upload)) {
                    $uploadPurged++;
                }
            }
        }

        return [
            'apply' => true,
            'candidate_count' => $posts->count(),
            'purged' => $posts->count(),
            'upload_purged' => $uploadPurged,
            'deferred_image_ids' => array_values(array_unique($deferredImageIds)),
        ];
    }
}
