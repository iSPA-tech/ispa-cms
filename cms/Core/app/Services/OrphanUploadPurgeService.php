<?php

namespace Cms\Core\Services;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Upload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class OrphanUploadPurgeService
{
    public function __construct(private readonly StorageReconciliationService $storage)
    {
    }

    public function purgeIfOrphan(Upload $upload): bool
    {
        if ((bool) config('ispa-cms.upload.r2_orphan_guard', false)
            && in_array($upload->type, ['image', 'avatar'], true)
            && (! data_get($upload->config, 'r2_cleanup_verified_at')
                || (Schema::hasTable('upload_r2_objects')
                    && DB::table('upload_r2_objects')->where('upload_id', $upload->getKey())->exists()))) {
            return false;
        }

        $paths = $this->storage->filesForBasePath($upload->getTargetFile(), $upload->disk ?: 'public');
        $purged = false;

        DB::transaction(function () use ($upload): void {
            if (DB::table(CoreTableConstant::UPLOAD_TARGET_PIVOT)
                ->where('upload_id', $upload->getKey())
                ->exists()) {
                return;
            }

            if (Schema::hasTable(CoreTableConstant::AUDIT_LOGS)) {
                AuditLog::query()
                    ->withTrashed()
                    ->where('target_type', Upload::class)
                    ->where('target_id', $upload->getKey())
                    ->forceDelete();
            }

            Upload::withoutEvents(static fn (): ?bool => $upload->forceDelete());
        });

        if (! $upload->exists) {
            foreach ($paths as $path) {
                Storage::disk($upload->disk ?: 'public')->delete($path);
            }
            $purged = true;
        }

        return $purged;
    }
}
