<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes LOCAL files whose bytes are hash-verified on R2 (the R2 local-eviction step).
 *
 * Hard preconditions (all enforced here):
 *  - master gate: filesystems.disks.r2.delete_local_after_sync must be true
 *    (except --dry-run) — only enable AFTER the nginx local→R2 fallback is
 *    live, otherwise legacy /storage URLs baked into old content break;
 *  - only rows with status=verified (real byte proof, never just synced);
 *  - only files older than --days (buffer for anything freshly written);
 *  - the file on disk must STILL hash-match what was verified — a drifted
 *    file is quarantined (content_changed), never deleted.
 *
 * Deletes files only — uploads/DB rows are never touched. Lazy variant
 * regeneration restores sources from R2 (ImageVariantService::restoreSourceFromR2).
 */
class R2EvictLocalCommand extends Command
{
    protected $signature = 'upload:r2-evict-local
        {--days=7 : Only evict files older than this many days}
        {--limit=2000 : Max files per run}
        {--max-runtime=240 : Stop cleanly after this many seconds}
        {--prefix= : Restrict to a relative directory, e.g. image/2026/07/}
        {--dry-run : Report only, delete nothing}';

    protected $description = 'Delete local files that are hash-verified on R2 (frees disk; R2 + nginx fallback serve them).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('filesystems.disks.r2.delete_local_after_sync')) {
            $this->error('R2_DELETE_LOCAL_AFTER_SYNC is off — refusing to evict. Enable it only after the nginx local→R2 fallback is live.');

            return self::FAILURE;
        }

        $lock = Cache::lock('upload:r2-evict-local', 3600);
        if (! $lock->get()) {
            $this->error('Another evict run is already in progress.');

            return self::FAILURE;
        }

        try {
            $cutoff = now()->subDays(max(1, (int) $this->option('days')));
            $evicted = 0;
            $skipped = 0;
            $quarantined = 0;
            $freedBytes = 0;
            $remoteErrors = 0;
            $startedAt = microtime(true);

            // Age gate uses the FILE's mtime (source_mtime), not the tracking
            // row's created_at — discovery backfilled rows for years-old
            // files only days ago.
            $rows = UploadR2Object::query()
                ->where('status', 'verified')
                ->whereNotNull('verified_at')
                ->whereNull('evicted_at')
                ->when($this->option('prefix'), fn ($q, $prefix) => $q->where('relative_path', 'like', rtrim($prefix, '/') . '/%'))
                ->where(fn ($query) => $query
                    ->where('source_mtime', '<=', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('source_mtime')->where('created_at', '<=', $cutoff)))
                ->orderBy('id')
                ->limit((int) $this->option('limit'))
                ->get();

            foreach ($rows as $object) {
                if (microtime(true) - $startedAt >= max(1, (int) $this->option('max-runtime'))) {
                    break;
                }
                $disk = Storage::disk($object->source_disk ?: 'public');

                if (! $disk->exists($object->relative_path)) {
                    // Already gone (manual cleanup) — just record the fact.
                    if (! $dryRun) {
                        $object->update(['evicted_at' => now()]);
                    }
                    $skipped++;
                    continue;
                }

                // Only delete the exact bytes that were verified on R2.
                $localHash = hash_file('sha256', $disk->path($object->relative_path));
                if ($localHash !== $object->source_sha256) {
                    if (! $dryRun) {
                        $object->update([
                            'status' => 'content_changed',
                            'previous_sha256' => $object->source_sha256,
                            'last_error' => 'local content changed after verification — quarantined instead of evicted',
                        ]);
                    }
                    $quarantined++;
                    continue;
                }

                // Historical hash proof is insufficient if the remote copy has since disappeared.
                try {
                    if (Storage::disk('r2')->size($object->relative_path) !== (int) $object->source_size) {
                        $remoteErrors++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    $remoteErrors++;
                    continue;
                }

                if (! $dryRun) {
                    if (! $disk->delete($object->relative_path)) {
                        $remoteErrors++;
                        continue;
                    }
                    $object->update(['evicted_at' => now()]);
                }

                $freedBytes += (int) $object->source_size;
                $evicted++;
            }

            $this->info(sprintf(
                'Evict: deleted=%d already_missing=%d quarantined=%d errors=%d freed=%s MB%s',
                $evicted,
                $skipped,
                $quarantined,
                $remoteErrors,
                number_format($freedBytes / 1048576, 1),
                $dryRun ? ' (dry-run)' : '',
            ));

            return $remoteErrors > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
