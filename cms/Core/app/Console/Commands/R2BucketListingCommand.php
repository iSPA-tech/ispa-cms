<?php

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Independent drift check between the tracking table and the real bucket
 * contents (paginated ListObjectsV2). tracked_but_remote_missing > 0 means
 * the app believes something is on R2 that genuinely isn't — a real
 * data-integrity problem, so the exit code fails.
 */
class R2BucketListingCommand extends Command
{
    protected $signature = 'upload:r2-bucket-listing';
    protected $description = 'Compare the R2 bucket listing against the tracking table and fail on real drift.';

    public function handle(): int
    {
        $client = Storage::disk('r2')->getClient();
        $bucket = config('filesystems.disks.r2.bucket');

        $tracked = UploadR2Object::query()
            ->whereIn('status', ['synced', 'verified'])
            ->pluck('relative_path')
            ->flip();
        $remoteKeys = collect();

        $paginator = $client->getPaginator('ListObjectsV2', ['Bucket' => $bucket]);
        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $entry) {
                $remoteKeys->push($entry['Key']);
            }
        }

        $trackedButRemoteMissing = $tracked->keys()->diff($remoteKeys);
        $remotePresentButUntracked = $remoteKeys->diff($tracked->keys());

        $this->info("tracked_but_remote_missing: {$trackedButRemoteMissing->count()}");
        $this->info("remote_present_but_untracked: {$remotePresentButUntracked->count()}");

        $trackedButRemoteMissing->take(20)->each(fn ($path) => $this->line("  missing: {$path}"));

        return $trackedButRemoteMissing->count() > 0 ? self::FAILURE : self::SUCCESS;
    }
}
