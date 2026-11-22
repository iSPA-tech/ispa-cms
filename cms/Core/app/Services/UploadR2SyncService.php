<?php

namespace Cms\Core\Services;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadR2SyncService
{
    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
    ];

    private const MAX_SNAPSHOT_ATTEMPTS = 3;

    public function syncObject(UploadR2Object $object): void
    {
        $locks = [];
        $acquired = [];
        // A project that prunes orphan images shares these locks with its cleanup job: `ispa-cms.upload.orphan_lock_prefix`.
        $lockPrefix = trim((string) config('ispa-cms.upload.orphan_lock_prefix', ''));
        if ($lockPrefix !== '') {
            if ($object->upload_id) {
                $locks[] = Cache::lock($lockPrefix . ':orphan-upload:' . $object->upload_id, 300);
            }
            $basePath = preg_replace('/\.\d+x\d+\.(?:webp|avif|jpe?g|png)$/i', '', $object->relative_path);
            $locks[] = Cache::lock($lockPrefix . ':orphan-path:' . sha1($basePath), 300);
            foreach ($locks as $lock) {
                if (! $lock->get()) {
                    foreach (array_reverse($acquired) as $held) {
                        $held->release();
                    }
                    return;
                }
                $acquired[] = $lock;
            }
        }

        try {
            $this->syncUnlocked($object);
        } finally {
            foreach (array_reverse($acquired) as $lock) {
                $lock->release();
            }
        }
    }

    private function syncUnlocked(UploadR2Object $object): void
    {
        if ($this->blockedForSync($object)) {
            // Quarantined — never auto-synced. Must go through upload:r2-approve-change first,
            // which moves status back to 'pending' and lets the normal sync queue pick it up.
            return;
        }

        $sourceDisk = $object->source_disk ?: 'public';

        if (!Storage::disk($sourceDisk)->exists($object->relative_path)) {
            $object->update(['status' => 'missing_source', 'last_error' => 'source file not found on local disk']);
            return;
        }

        $object->increment('attempts');
        $liveAbsolute = Storage::disk($sourceDisk)->path($object->relative_path);
        $snapshotPath = null;

        try {
            $snapshotPath = $this->createStableSnapshot($liveAbsolute);
            $hash = hash_file('sha256', $snapshotPath);
            $size = filesize($snapshotPath);

            // Defense in depth alongside R2ObjectTracker's own quarantine check: this covers the
            // narrower case of an object approved out of quarantine, or one that reached 'pending'
            // some other way, whose content changed YET AGAIN before this sync attempt actually ran.
            $wasEverSynced = in_array($object->status, ['synced', 'verified'], true);
            if ($wasEverSynced && $object->source_sha256 !== null && $object->source_sha256 !== $hash) {
                $object->update([
                    'status' => 'content_changed',
                    'previous_sha256' => $object->source_sha256,
                    'source_sha256' => $hash,
                    'source_size' => $size,
                    'needs_cache_purge' => true,
                    'last_error' => 'content changed at an already-synced path — quarantined pending manual review',
                ]);
                Log::warning('UploadR2SyncService: quarantined instead of auto-overwriting R2', ['relative_path' => $object->relative_path]);
                return;
            }

            if ($this->blockedForSync($object)) {
                return;
            }

            $stream = fopen($snapshotPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('failed to open snapshot for reading');
            }

            try {
                $extension = strtolower(pathinfo($object->relative_path, PATHINFO_EXTENSION));
                $contentType = self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';

                Storage::disk('r2')->writeStream($object->relative_path, $stream, [
                    'ContentType' => $contentType,
                    'CacheControl' => 'public, max-age=31536000, immutable',
                    'Metadata' => [(string) config('ispa-cms.upload.source_hash_metadata_key', 'source-sha256') => $hash], // weak signal only — see the migration table's schema note
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $remoteSize = Storage::disk('r2')->size($object->relative_path);
            if ($remoteSize !== $size) {
                throw new \RuntimeException("size mismatch after upload: local={$size} remote={$remoteSize}");
            }

            $object->update([
                'status' => 'synced',
                'source_sha256' => $hash,
                'source_size' => $size,
                'synced_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $object->update(['status' => 'failed', 'last_error' => substr($e->getMessage(), 0, 1000)]);
        } finally {
            if ($snapshotPath && file_exists($snapshotPath)) {
                @unlink($snapshotPath);
            }
        }
    }

    private function blockedForSync(UploadR2Object $object): bool
    {
        $status = UploadR2Object::query()->whereKey($object->getKey())->value('status');

        return $status === null || in_array($status, ['content_changed', 'deleting'], true);
    }

    /**
     * copy() alone is not atomic — if the source is overwritten DURING the copy syscall, the
     * snapshot could contain a torn mix of old and new bytes. Stat before and after; if
     * size/mtime/inode moved, the snapshot is untrustworthy — discard and retry, bounded.
     */
    private function createStableSnapshot(string $liveAbsolute): string
    {
        for ($attempt = 1; $attempt <= self::MAX_SNAPSHOT_ATTEMPTS; $attempt++) {
            $preStat = @stat($liveAbsolute);
            if ($preStat === false) {
                throw new \RuntimeException('source file vanished before snapshot could be taken');
            }

            $snapshotPath = tempnam(sys_get_temp_dir(), 'r2sync_');
            if (!copy($liveAbsolute, $snapshotPath)) {
                @unlink($snapshotPath);
                throw new \RuntimeException('failed to create local snapshot');
            }

            $postStat = @stat($liveAbsolute);
            $stable = $postStat !== false
                && $preStat['size'] === $postStat['size']
                && $preStat['mtime'] === $postStat['mtime']
                && $preStat['ino'] === $postStat['ino'];

            if ($stable) {
                return $snapshotPath;
            }

            @unlink($snapshotPath);
            if ($attempt === self::MAX_SNAPSHOT_ATTEMPTS) {
                throw new \RuntimeException("source changed during snapshot after {$attempt} attempts");
            }
        }

        throw new \RuntimeException('unreachable');
    }

    /**
     * De-duplicated, bucketed priority query: pre-aggregated subqueries (one row per upload_id)
     * joined ONCE into the objects table, so an upload tied to multiple posts/places can never
     * fan out into duplicate rows. Explicit numbered priority buckets so recency can never
     * override intended tier ordering. status='pending' naturally excludes content_changed
     * (quarantined) rows without any extra filter needed.
     */
    public function pendingObjectsInPriorityOrder(int $limit): \Illuminate\Support\Collection
    {
        $postPriority = DB::table('upload_target_pivot as utp')
            ->join('posts', 'posts.id', '=', 'utp.target_id')
            ->where('utp.target_type', 'Cms\Content\Models\Post')
            ->groupBy('utp.upload_id')
            ->select('utp.upload_id')
            ->selectRaw('MAX(CASE WHEN posts.status = "status_published" THEN 1 ELSE 0 END) as has_published_post')
            ->selectRaw('MAX(COALESCE(posts.published_at, posts.created_at)) as post_recency');

        $placePriority = DB::table('upload_target_pivot as utp')
            ->join('places', 'places.id', '=', 'utp.target_id')
            ->where('utp.target_type', 'App\Models\Place')
            ->groupBy('utp.upload_id')
            ->select('utp.upload_id')
            ->selectRaw('MAX(places.created_at) as place_recency');

        // Posts (Content) and places (GeoPlaces) belong to modules that can be disabled: a site created without one
        // has no such table, so its bucket is skipped instead of failing the whole sync.
        $hasPosts = Schema::hasTable('posts');
        $hasPlaces = Schema::hasTable('places');
        $publishedPost = $hasPosts ? 'post_agg.has_published_post = 1' : '1 = 0';
        $postRecency = $hasPosts ? 'post_agg.post_recency' : 'NULL';
        $placeRecency = $hasPlaces ? 'place_agg.place_recency' : 'NULL';

        $uploadPriority = DB::table('uploads')
            ->when($hasPosts, fn ($q) => $q->leftJoinSub($postPriority, 'post_agg', 'post_agg.upload_id', '=', 'uploads.id'))
            ->when($hasPlaces, fn ($q) => $q->leftJoinSub($placePriority, 'place_agg', 'place_agg.upload_id', '=', 'uploads.id'))
            ->select('uploads.id as upload_id')
            ->selectRaw("
                CASE
                    WHEN {$publishedPost} THEN 1
                    WHEN {$placeRecency} IS NOT NULL THEN 2
                    WHEN {$postRecency} IS NOT NULL THEN 3
                    ELSE 4
                END as priority_bucket
            ")
            ->selectRaw("COALESCE({$postRecency}, {$placeRecency}, uploads.created_at) as recency");

        return DB::table('upload_r2_objects as o')
            ->leftJoinSub($uploadPriority, 'up', 'up.upload_id', '=', 'o.upload_id')
            ->where('o.status', 'pending') // excludes content_changed, synced, verified, failed, missing_source — all intentional
            ->where(function ($q) {
                $q->whereNull('o.next_retry_at')->orWhere('o.next_retry_at', '<=', now());
            })
            ->orderByRaw('COALESCE(up.priority_bucket, 4) ASC')
            ->orderByRaw('COALESCE(up.recency, o.created_at) DESC')
            ->limit($limit)
            ->select('o.id', 'o.upload_id', 'o.relative_path')
            ->get();
    }

    /**
     * Flip uploads to CDN serving: stamp r2_synced_at on every upload whose
     * tracked objects (original + variants) are ALL status=synced. The Upload
     * URL accessors switch to the R2 public URL from that moment on.
     *
     * @return int Number of uploads flipped in this pass.
     */
    public function markFullySyncedUploads(): int
    {
        // Resolve candidates first, then update in small chunks — a single
        // UPDATE with the grouped subquery holds locks long enough to
        // deadlock against a concurrently running sync worker.
        $candidateIds = DB::table('upload_r2_objects')
            ->select('upload_id')
            ->whereNotNull('upload_id')
            ->groupBy('upload_id')
            ->havingRaw("sum(case when status not in ('synced', 'verified') then 1 else 0 end) = 0")
            ->pluck('upload_id');

        $flipped = 0;
        foreach ($candidateIds->chunk(500) as $chunk) {
            $flipped += DB::table('uploads')
                ->whereNull('r2_synced_at')
                ->whereIn('id', $chunk->all())
                ->update(['r2_synced_at' => now()]);
        }

        return $flipped;
    }
}
