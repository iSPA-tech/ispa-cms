<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Artifacts;

use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Real database implementation of {@see WidgetArtifactManifestRepository}
 * (plan Chunk 2 Task 13b; bound in
 * {@see \Cms\Widget\Providers\WidgetServiceProvider::boot()} after Task 13a's
 * `widget_artifact_manifests` table exists).
 *
 * Class-name note: this file imports the Eloquent row model
 * {@see \Cms\Widget\Models\WidgetArtifactManifest} under the alias
 * `WidgetArtifactManifestModel` because the immutable VO this repository's
 * own interface speaks in, {@see WidgetArtifactManifest} (same short class
 * name, different namespace -- `Cms\Widget\Rendering\Artifacts`), is what
 * every public method signature here uses.
 *
 * Beyond the frozen `WidgetArtifactManifestRepository` interface (kept
 * exactly as Task 10 defined it), this class exposes two EXTRA primitive
 * methods -- {@see self::compareAndSwapAttestation()} and
 * {@see self::compareAndSwapControl()} -- proving the raw optimistic
 * compare-and-swap mechanics Task 15b (approve/revoke) and Task 15c/24
 * (emergency block/clear) will build their business semantics on top of.
 * Nothing here approves, revokes, blocks, or clears anything -- that is
 * explicitly out of this task's scope (plan Task 13b non-goal).
 */
final class DatabaseWidgetArtifactManifestRepository implements WidgetArtifactManifestRepository
{
    public function findByProjectAndManifestHash(string $projectName, string $manifestHash): ?WidgetArtifactManifest
    {
        $row = $this->findRow($projectName, $manifestHash);

        return $row === null ? null : $this->toVo($row);
    }

    public function putPending(WidgetArtifactManifest $manifest): WidgetArtifactManifest
    {
        if ($manifest->status !== WidgetArtifactManifest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                'putPending() only accepts a PENDING manifest; approval/revocation persistence is owned by Task 15a/15b.'
            );
        }

        $existing = $this->findRow($manifest->projectName, $manifest->manifestHash);
        if ($existing !== null) {
            // Idempotent reuse: never overwritten, never a false conflict --
            // two manifests differing in ANY canonical field necessarily
            // differ in manifest_hash (Task 10 contract).
            return $this->toVo($existing);
        }

        $blobRow = WidgetArtifactBlob::query()
            ->where('project_name', $manifest->projectName)
            ->where('artifact_hash', $manifest->artifactBlob->artifactHash)
            ->first();

        if ($blobRow === null) {
            // Defensive, application-level check: the composite
            // Manifest -> Blob foreign key (2026_07_17_150008) additionally
            // enforces this AT THE DATABASE LAYER on mysql/pgsql
            // (WidgetDatabaseIntegrityTest::test_manifest_rejects_cross_project_blob_reference),
            // but this repository never attempts an insert doomed to fail
            // that constraint -- it fails fast with a clear message instead.
            throw new InvalidArgumentException(sprintf(
                "Cannot persist a Manifest whose referenced Blob (project '%s', artifact_hash '%s') has not been published in that project yet.",
                $manifest->projectName,
                $manifest->artifactBlob->artifactHash,
            ));
        }

        try {
            // PostgreSQL-specific correctness requirement (see
            // WidgetIdempotencyService::tryInsertClaim()'s docblock for the
            // full explanation): wrap the insert in its own
            // DB::transaction() so a caught unique-constraint violation only
            // rolls back to a SAVEPOINT instead of poisoning the whole
            // enclosing transaction.
            $row = DB::transaction(static fn () => WidgetArtifactManifestModel::query()->create([
                'project_name' => $manifest->projectName,
                'manifest_hash' => $manifest->manifestHash,
                'artifact_blob_id' => $blobRow->id,
                'generator_version' => $manifest->generatorVersion,
                'renderer_key' => $manifest->rendererKey,
                'renderer_version' => $manifest->rendererVersion,
                'applicability' => $manifest->applicability,
                'executable_surface_fingerprint_hash' => $manifest->executableSurfaceFingerprintHash,
                'canonical_schema_hash' => $manifest->canonicalSchemaHash,
                'provider_contracts' => $manifest->providerContracts,
                'trust_policy_version' => $manifest->trustPolicyVersion,
                'trust_lane' => $manifest->trustLane,
                'eligibility_state' => 'pending',
                'attestation_version' => 1,
                'control_version' => 1,
                'blocked' => false,
            ]));
        } catch (QueryException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Lost a race to insert the same (project_name, manifest_hash)
            // row -- re-select and reuse (same defensive pattern as
            // DatabaseWidgetArtifactBlobRepository::put()).
            $race = $this->findRow($manifest->projectName, $manifest->manifestHash);
            if ($race === null) {
                throw $exception;
            }

            return $this->toVo($race);
        }

        return $this->toVo($row);
    }

    /**
     * Primitive optimistic compare-and-swap on the monotonic
     * `attestation_version` counter: `UPDATE ... WHERE attestation_version
     * = $expectedAttestationVersion`, affected-rows checked. Returns false
     * (never throws) when the expected version is stale -- the caller
     * (a future Task 15b service) decides what a failed CAS means. No
     * approve/revoke business semantics live here.
     *
     * @param array<string, mixed> $changes must include the new
     *        attestation_version among the columns to set -- enforced below:
     *        omitting it would make every "successful" CAS leave the counter
     *        unchanged, so the same expected version keeps matching forever
     *        (a silent always-succeeds ABA hole).
     */
    public function compareAndSwapAttestation(string $projectName, string $manifestHash, int $expectedAttestationVersion, array $changes): bool
    {
        if (!array_key_exists('attestation_version', $changes)) {
            throw new InvalidArgumentException('compareAndSwapAttestation() $changes must include the new attestation_version -- otherwise the counter never advances and the CAS degrades to an unfenced update.');
        }

        $affected = WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $manifestHash)
            ->where('attestation_version', $expectedAttestationVersion)
            ->update($changes);

        return $affected > 0;
    }

    /**
     * Primitive optimistic compare-and-swap on the INDEPENDENT monotonic
     * `control_version` counter (emergency block/clear axis). Deliberately
     * a separate method body from {@see self::compareAndSwapAttestation()}
     * rather than a shared parametrized helper: the two counters are
     * independent axes (architecture.md; proven by
     * WidgetDatabaseLifecycleTest::test_manifest_eligibility_and_emergency_control_are_independent_and_monotonic_while_canonical_fields_never_mutate),
     * and a shared helper risks one transition's WHERE clause silently
     * drifting to affect the other's counter.
     *
     * @param array<string, mixed> $changes must include the new
     *        control_version -- enforced below for the same
     *        silent-always-succeeds ABA reason as
     *        {@see self::compareAndSwapAttestation()}.
     */
    public function compareAndSwapControl(string $projectName, string $manifestHash, int $expectedControlVersion, array $changes): bool
    {
        if (!array_key_exists('control_version', $changes)) {
            throw new InvalidArgumentException('compareAndSwapControl() $changes must include the new control_version -- otherwise the counter never advances and the CAS degrades to an unfenced update.');
        }

        $affected = WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $manifestHash)
            ->where('control_version', $expectedControlVersion)
            ->update($changes);

        return $affected > 0;
    }

    private function findRow(string $projectName, string $manifestHash): ?WidgetArtifactManifestModel
    {
        return WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $manifestHash)
            ->first();
    }

    private function toVo(WidgetArtifactManifestModel $row): WidgetArtifactManifest
    {
        $blobRow = WidgetArtifactBlob::query()->findOrFail($row->artifact_blob_id);

        return new WidgetArtifactManifest(
            projectName: $row->project_name,
            manifestHash: $row->manifest_hash,
            artifactBlob: new WidgetArtifactBlobDescriptor(
                projectName: $blobRow->project_name,
                artifactHash: $blobRow->artifact_hash,
                byteSize: (int) $blobRow->byte_size,
                mediaType: $blobRow->media_type,
                checksum: $blobRow->checksum,
                storageDisk: $blobRow->storage_disk,
                storageKey: $blobRow->storage_key,
                createdAt: DateTimeImmutable::createFromInterface($blobRow->created_at),
            ),
            generatorVersion: $row->generator_version,
            rendererKey: $row->renderer_key,
            rendererVersion: $row->renderer_version,
            applicability: $row->applicability,
            executableSurfaceFingerprintHash: $row->executable_surface_fingerprint_hash,
            canonicalSchemaHash: $row->canonical_schema_hash,
            providerContracts: $row->provider_contracts,
            trustPolicyVersion: $row->trust_policy_version,
            trustLane: $row->trust_lane,
            createdAt: DateTimeImmutable::createFromInterface($row->created_at),
            status: $row->eligibility_state,
        );
    }

    /**
     * Same cross-engine detector as
     * DatabaseWidgetArtifactBlobRepository::isUniqueViolation() (both
     * generalize cms/Achievements/app/Recorders/AchievementRecorderImpl.php:124-132).
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key value')
            || (string) $exception->getCode() === '23505';
    }
}
