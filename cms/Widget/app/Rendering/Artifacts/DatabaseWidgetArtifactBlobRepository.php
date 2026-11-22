<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Artifacts;

use Cms\Widget\Models\WidgetArtifactBlob;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Real database + filesystem implementation of
 * {@see WidgetArtifactBlobRepository} (plan Chunk 2 Task 13b; bound in
 * {@see \Cms\Widget\Providers\WidgetServiceProvider::boot()} after Task 13a's
 * `widget_artifact_blobs` table exists).
 *
 * Delegates the actual byte I/O to the injected
 * {@see FilesystemWidgetArtifactBlobStore} (Task 5a's approved single-node
 * local-disk topology) and owns only the row/metadata side: existence
 * lookup, the checksum-then-reuse-then-publish sequence, and translating a
 * lost insert race into a graceful re-select rather than a crash.
 *
 * Satisfies the exact same behavioral contract Task 10's
 * {@see \Cms\Widget\Tests\Fakes\InMemoryWidgetArtifactBlobRepository} fake
 * already proves (verify-before-visible, reuse-if-identical,
 * hard-fail-never-overwrite, strict project isolation) -- see
 * {@see \Cms\Widget\Tests\Support\WidgetArtifactRepositoryContractAssertions}
 * for the shared executable proof both implementations satisfy.
 */
final class DatabaseWidgetArtifactBlobRepository implements WidgetArtifactBlobRepository
{
    public function __construct(
        private readonly FilesystemWidgetArtifactBlobStore $store,
    ) {
    }

    public function findByProjectAndHash(string $projectName, string $artifactHash): ?WidgetArtifactBlobDescriptor
    {
        $row = $this->findRow($projectName, $artifactHash);

        return $row === null ? null : $this->toDescriptor($row);
    }

    public function put(string $projectName, string $artifactHash, string $bytes, string $mediaType): WidgetArtifactBlobDescriptor
    {
        // Step 1 of the publication flow: verify the claimed identity
        // against the actual bytes BEFORE anything becomes visible. A
        // mismatch here means the caller's own artifact_hash computation
        // disagrees with the bytes it is publishing -- refuse outright.
        if (!hash_equals(hash('sha256', $bytes), $artifactHash)) {
            throw WidgetArtifactIntegrityException::checksumMismatch($projectName, $artifactHash);
        }

        $existing = $this->findRow($projectName, $artifactHash);

        if ($existing !== null) {
            // Existing-byte verification, three-way (post-review rider):
            $stored = $this->store->read($existing->storage_key);

            if ($stored === null) {
                // File ABSENT but row present (lost file: disk mishap,
                // partial restore, manual cleanup): self-heal by writing
                // the checksum-verified bytes in hand back to the row's
                // deterministic content-addressed key -- store->write()
                // recomputes storageKeyFor(project, hash), which is the
                // same pure function that produced the row's storage_key,
                // so the heal lands exactly where the row points. Absence
                // is NOT an integrity failure: there are no divergent
                // bytes to protect, only a gap to restore.
                $this->store->write($projectName, $artifactHash, $bytes);

                return $this->toDescriptor($existing);
            }

            if ($stored !== $bytes) {
                // Divergent stored bytes (disk corruption/tamper, or a
                // caller bug bypassing the checksum check above via a
                // crafted hash) -> hard integrity failure, the on-disk
                // original retained untouched -- never overwritten.
                throw WidgetArtifactIntegrityException::hashCollisionWithDifferentBytes($projectName, $artifactHash);
            }

            // Identical -> idempotent reuse, no rewrite, no new createdAt.
            return $this->toDescriptor($existing);
        }

        $written = $this->store->write($projectName, $artifactHash, $bytes);

        try {
            // PostgreSQL-specific correctness requirement (see
            // WidgetIdempotencyService::tryInsertClaim()'s docblock for the
            // full explanation, discovered running the real
            // widget-site-layout-db-matrix pgsql leg): wrap the insert in
            // its own DB::transaction() so Laravel issues a SAVEPOINT here.
            // A caught unique-constraint violation then only rolls back to
            // that savepoint instead of poisoning the entire enclosing
            // transaction (which would otherwise make the re-select below
            // fail with SQLSTATE 25P02 on PostgreSQL, even though MySQL and
            // SQLite tolerate the same catch-and-continue pattern fine).
            $row = DB::transaction(static fn () => WidgetArtifactBlob::query()->create([
                'project_name' => $projectName,
                'artifact_hash' => $artifactHash,
                'storage_disk' => $written['storage_disk'],
                'storage_key' => $written['storage_key'],
                'byte_size' => strlen($bytes),
                'media_type' => $mediaType,
                'checksum' => $written['checksum'],
            ]));
        } catch (QueryException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Lost a race to insert the same (project_name, artifact_hash)
            // row: the file is already safely published at a deterministic,
            // content-addressed key (idempotent write), so simply re-select
            // and reuse rather than surface a crash. True concurrent-insert
            // proof is Task 16+/24b's owner; this is the defensive
            // cross-engine detector only (AchievementRecorderImpl.php:124-132
            // pattern).
            $race = $this->findRow($projectName, $artifactHash);
            if ($race === null) {
                throw $exception;
            }

            return $this->toDescriptor($race);
        }

        return $this->toDescriptor($row);
    }

    private function findRow(string $projectName, string $artifactHash): ?WidgetArtifactBlob
    {
        return WidgetArtifactBlob::query()
            ->where('project_name', $projectName)
            ->where('artifact_hash', $artifactHash)
            ->first();
    }

    private function toDescriptor(WidgetArtifactBlob $row): WidgetArtifactBlobDescriptor
    {
        return new WidgetArtifactBlobDescriptor(
            projectName: $row->project_name,
            artifactHash: $row->artifact_hash,
            byteSize: (int) $row->byte_size,
            mediaType: $row->media_type,
            checksum: $row->checksum,
            storageDisk: $row->storage_disk,
            storageKey: $row->storage_key,
            createdAt: DateTimeImmutable::createFromInterface($row->created_at),
        );
    }

    /**
     * Cross-engine UNIQUE-violation detector, generalized from
     * cms/Achievements/app/Recorders/AchievementRecorderImpl.php:124-132
     * (cited by docs/architecture/adr-widget-mutation-idempotency.md
     * decision 2) to this repository's own `(project_name, artifact_hash)`
     * unique index.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed') // SQLite
            || str_contains($message, 'Duplicate entry')          // MySQL
            || str_contains($message, 'duplicate key value')      // PostgreSQL
            || (string) $exception->getCode() === '23505';        // PostgreSQL SQLSTATE
    }
}
