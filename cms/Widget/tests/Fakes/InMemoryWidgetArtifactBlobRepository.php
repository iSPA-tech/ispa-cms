<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Fakes;

use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobDescriptor;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactIntegrityException;
use DateTimeImmutable;

/**
 * In-memory WidgetArtifactBlobRepository test double (plan Chunk 1 Task 10).
 *
 * Simulates the artifact-store ADR's publication flow entirely in PHP
 * arrays -- no real filesystem or database I/O -- while enforcing the exact
 * same visible behavior a real adapter must: verify-before-visible
 * (checksum), reuse-if-identical, hard-fail-never-overwrite on collision,
 * and strict `(projectName, artifactHash)` isolation. This class is the
 * ONLY implementation of WidgetArtifactBlobRepository in this
 * persistence-agnostic slice; Task 13b's real database/storage adapter must
 * satisfy the same behavior contract
 * {@see \Cms\Widget\Tests\Unit\Rendering\WidgetArtifactRepositoryTest}
 * encodes. That reuse is NOT drop-in, though: the suite instantiates this
 * fake concretely and its dedup/isolation proofs use the test-only
 * countRowsForProject() helper below (fake-only, not part of the
 * interface), so Task 13b must first extract an abstract contract-test
 * base with repository-factory and row-count hooks before running the
 * same assertions against its real adapter.
 *
 * Adapter acceptance / topology note (Task 5a evidence,
 * docs/evidence/widget-site-layout-preflight.md): this fake holds all state
 * in one PHP array for the lifetime of a single test process, so it proves
 * ONLY single-process atomicity-of-visibility, never cross-process/
 * cross-node atomicity. It neither proves nor assumes shared/networked
 * storage; a future production adapter binding per-node local disk on
 * MULTIPLE public nodes without synchronous distribution would still need
 * its OWN Task 5a-grade evidence pass regardless of what this fake proves.
 */
final class InMemoryWidgetArtifactBlobRepository implements WidgetArtifactBlobRepository
{
    /**
     * @var array<string, array<string, array{descriptor: WidgetArtifactBlobDescriptor, bytes: string}>>
     *      Keyed by [projectName][artifactHash].
     */
    private array $rows = [];

    public function findByProjectAndHash(string $projectName, string $artifactHash): ?WidgetArtifactBlobDescriptor
    {
        return $this->rows[$projectName][$artifactHash]['descriptor'] ?? null;
    }

    /**
     * `mediaType` is descriptive metadata, not identity: reuse of an
     * existing `(projectName, artifactHash)` row retains the ORIGINAL
     * descriptor (including its original mediaType) untouched; whether a
     * mediaType mismatch on reuse should warn or fail is Task 13b's
     * real-adapter policy decision, not fixed here.
     */
    public function put(string $projectName, string $artifactHash, string $bytes, string $mediaType): WidgetArtifactBlobDescriptor
    {
        // "Checksum" step: independently verify the claimed identity
        // against the actual bytes BEFORE anything is written to $this->rows.
        // The array assignment further below is the "atomic rename" --
        // nothing is mutated until that single statement, simulating
        // temp-write-then-verify-then-publish. A mismatch here leaves
        // $this->rows completely untouched (no orphaned/partial entry).
        if (!hash_equals(hash('sha256', $bytes), $artifactHash)) {
            throw WidgetArtifactIntegrityException::checksumMismatch($projectName, $artifactHash);
        }

        $existing = $this->rows[$projectName][$artifactHash] ?? null;
        if ($existing !== null) {
            if ($existing['bytes'] === $bytes) {
                // Reuse: identical content already published under this
                // exact key -- return the retained original descriptor
                // untouched (no rewrite, no new createdAt).
                return $existing['descriptor'];
            }

            // Hard integrity failure: never overwrite. The retained
            // original row is left exactly as it was.
            throw WidgetArtifactIntegrityException::hashCollisionWithDifferentBytes($projectName, $artifactHash);
        }

        $descriptor = new WidgetArtifactBlobDescriptor(
            projectName: $projectName,
            artifactHash: $artifactHash,
            byteSize: strlen($bytes),
            mediaType: $mediaType,
            checksum: $artifactHash,
            storageDisk: 'widget-artifacts',
            storageKey: $projectName . '/' . $artifactHash,
            createdAt: new DateTimeImmutable(),
        );

        $this->rows[$projectName][$artifactHash] = ['descriptor' => $descriptor, 'bytes' => $bytes];

        return $descriptor;
    }

    /**
     * Test-only introspection helper (not part of the production
     * interface): total row count for one project, used to prove dedup
     * (repeated identical put() calls never grow row count) and cross-
     * project isolation without reaching into private state.
     */
    public function countRowsForProject(string $projectName): int
    {
        return count($this->rows[$projectName] ?? []);
    }
}
