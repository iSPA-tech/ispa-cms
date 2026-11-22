<?php

namespace Cms\Core\Services;

use Cms\Core\Models\UploadR2Object;
use Illuminate\Database\QueryException;

class UploadR2ObjectRepository
{
    public function findByPath(string $relativePath): ?UploadR2Object
    {
        return UploadR2Object::where('relative_path', $relativePath)->first();
    }

    /**
     * Race-safe: if a concurrent caller (a request-path hook and a discovery scan touching the
     * same brand-new path at nearly the same moment) already inserted this row between the
     * caller's findByPath() check and this call, the unique constraint on relative_path throws a
     * duplicate-key error (MySQL/MariaDB code 1062) — caught here and treated as "someone else
     * already created it," falling through to a normal fingerprint update instead of propagating
     * an error for what is actually a harmless race, not a real failure.
     */
    public function create(string $relativePath, ?int $uploadId, string $sourceDisk, int $size, int $mtime, string $hash, string $kind): UploadR2Object
    {
        try {
            return UploadR2Object::create([
                'upload_id' => $uploadId,
                'relative_path' => $relativePath,
                'source_disk' => $sourceDisk,
                'kind' => $kind,
                'source_size' => $size,
                'source_mtime' => date('Y-m-d H:i:s', $mtime),
                'source_sha256' => $hash,
                'status' => 'pending',
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $existing = $this->findByPath($relativePath);
                if ($existing) {
                    $this->updateFingerprint($existing, $uploadId, $sourceDisk, $size, $mtime, $hash, contentChanged: $existing->source_sha256 !== $hash);
                    return $existing->fresh();
                }
            }
            throw $e;
        }
    }

    /**
     * An object that was ALREADY successfully synced/verified and whose content changed at the
     * same path is quarantined (status=content_changed), never silently reset to pending — pending
     * would let the very next scheduled sync run auto-overwrite R2 with zero human review, which
     * is exactly what the immutable-key policy exists to prevent. An object that was NEVER
     * successfully synced yet just gets its fingerprint updated normally.
     */
    public function updateFingerprint(UploadR2Object $object, ?int $uploadId, string $sourceDisk, int $size, int $mtime, string $hash, bool $contentChanged): void
    {
        $base = [
            'upload_id' => $uploadId ?? $object->upload_id,
            'source_disk' => $sourceDisk,
            'source_mtime' => date('Y-m-d H:i:s', $mtime),
        ];

        if (!$contentChanged) {
            $object->update(array_merge($base, ['source_size' => $size]));
            return;
        }

        $wasEverSynced = in_array($object->status, ['synced', 'verified'], true);

        if ($wasEverSynced) {
            $object->update(array_merge($base, [
                'status' => 'content_changed',
                'previous_sha256' => $object->source_sha256,
                'source_sha256' => $hash,
                'source_size' => $size,
                'needs_cache_purge' => true,
            ]));
            return;
        }

        $object->update(array_merge($base, [
            'status' => 'pending',
            'source_sha256' => $hash,
            'source_size' => $size,
        ]));
    }
}
