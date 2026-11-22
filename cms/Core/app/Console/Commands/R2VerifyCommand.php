<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Two-mode remote verification of replicated R2 objects.
 *
 * --mode=head  → cheap existence+size check; stamps head_checked_at only,
 *                never promotes status (a size match is not byte proof).
 * --mode=hash  → downloads the actual object, streams it through sha256 and
 *                compares against the locally-computed source hash; the ONLY
 *                check that proves byte-accuracy → status=verified.
 */
class R2VerifyCommand extends Command
{
    protected $signature = 'upload:r2-verify
        {--mode=head : head (cheap existence+size) or hash (real remote-bytes proof)}
        {--recent-hours=} {--include-failed} {--all}
        {--sample=} {--rotate-of= : e.g. --sample=1 --rotate-of=4 checks a rotating 1/4 slice}
        {--only-unverified : Check synced objects still awaiting hash verification}
        {--prefix= : Restrict to a relative directory, e.g. image/2026/07/}
        {--after-id=0 : resume checkpoint, only process id greater than this}
        {--limit=500} {--max-runtime=1800}';

    protected $description = 'Verify replicated objects against R2 (HEAD size check or streamed HASH byte proof).';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['head', 'hash'], true)) {
            $this->error('Mode must be head or hash.');
            return self::FAILURE;
        }
        $lock = Cache::lock("upload:r2-verify:{$mode}", 3600);
        if (! $lock->get()) {
            $this->error("Another r2-verify --mode={$mode} run is already in progress.");

            return self::FAILURE;
        }

        try {
            $query = UploadR2Object::query()
                ->whereIn('status', $this->option('include-failed') ? ['synced', 'verified', 'failed'] : ['synced', 'verified'])
                ->where('id', '>', (int) $this->option('after-id'))
                ->when($this->option('prefix'), fn ($q, $prefix) => $q->where('relative_path', 'like', rtrim($prefix, '/') . '/%'))
                ->orderBy('id');

            if ($this->option('only-unverified')) {
                $query->where('status', 'synced');
            }
            if ($hours = $this->option('recent-hours')) {
                $cutoff = now()->subHours((int) $hours);
                $query->where(function ($q) use ($cutoff) {
                    $q->where('synced_at', '>=', $cutoff)->orWhere('verified_at', '>=', $cutoff);
                    if ($this->option('include-failed')) {
                        $q->orWhere('status', 'failed');
                    }
                });
            }
            if ($rotateOf = $this->option('rotate-of')) {
                $slice = (int) ($this->option('sample') ?? 0);
                $query->whereRaw('id % ? = ?', [(int) $rotateOf, $slice]);
            }

            $query->limit((int) $this->option('limit'));

            $client = Storage::disk('r2')->getClient();
            $bucket = config('filesystems.disks.r2.bucket');
            $startedAt = microtime(true);
            $maxRuntime = (int) $this->option('max-runtime');
            $checked = 0;
            $mismatches = 0;
            $lastId = null;

            foreach ($query->cursor() as $object) {
                if ((microtime(true) - $startedAt) > $maxRuntime) {
                    $this->warn("Max runtime reached at id={$lastId}. Resume with --after-id={$lastId}");
                    break;
                }
                $lastId = $object->id;
                $checked++;

                try {
                    if ($mode === 'hash') {
                        // Streamed in 1MB chunks — never loads the whole body
                        // into memory.
                        $result = $client->getObject(['Bucket' => $bucket, 'Key' => $object->relative_path]);
                        $body = $result['Body'];
                        $ctx = hash_init('sha256');
                        while (! $body->eof()) {
                            hash_update($ctx, $body->read(1024 * 1024));
                        }
                        $remoteHash = hash_final($ctx);
                        $remoteSize = (int) $result['ContentLength'];

                        if ($remoteHash === $object->source_sha256 && $remoteSize === (int) $object->source_size) {
                            $object->update(['status' => 'verified', 'verified_at' => now()]);
                        } else {
                            $mismatches++;
                            $object->update(['status' => 'failed', 'last_error' => 'HASH mode verify mismatch: remote bytes do not match local source']);
                        }
                    } else {
                        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $object->relative_path]);
                        if ((int) $head['ContentLength'] === (int) $object->source_size) {
                            $object->update(['head_checked_at' => now()]);
                        } else {
                            $mismatches++;
                            $object->update(['status' => 'failed', 'last_error' => 'HEAD mode verify mismatch: remote size differs']);
                        }
                    }
                } catch (\Throwable $e) {
                    $mismatches++;
                    $object->update(['status' => 'failed', 'last_error' => 'verify request failed: ' . substr($e->getMessage(), 0, 500)]);
                }
            }

            $this->info("Verified ({$mode}): checked={$checked} mismatches={$mismatches} last_id=" . ($lastId ?? 'n/a'));

            return $mismatches > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
