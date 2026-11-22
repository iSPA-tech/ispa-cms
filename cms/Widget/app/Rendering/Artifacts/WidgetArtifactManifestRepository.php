<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Artifacts;

/**
 * Root contract for immutable, project-scoped Widget artifact Manifest
 * storage (plan Chunk 1 Task 10; architecture.md "widget_artifact_manifests").
 *
 * This slice is persistence-agnostic:
 * {@see NullWidgetArtifactManifestRepository} is the only production
 * IMPLEMENTATION -- no container binding exists yet (WidgetServiceProvider
 * currently binds only the root WidgetRenderer contract); it is the
 * intended default binding once a consumer wires one (Task 15a). An
 * in-memory test double proves the dedup/isolation contract in
 * {@see \Cms\Widget\Tests\Unit\Rendering\WidgetArtifactRepositoryTest}. Task
 * 13b binds the real database repository against this same interface after
 * Task 13a creates the table; Task 15b owns the approve/revoke transition
 * this slice deliberately does not implement.
 *
 * Identity is the composite `(project_name, manifest_hash)`. Many
 * same-project Manifests may reference one Blob -- this repository never
 * treats "a Manifest referencing an already-used Blob" as any kind of
 * conflict; conflict is scoped strictly to `manifest_hash` collisions
 * within one project.
 */
interface WidgetArtifactManifestRepository
{
    /**
     * Read-only lookup. Returns null on a miss; never throws for an
     * unknown hash.
     */
    public function findByProjectAndManifestHash(string $projectName, string $manifestHash): ?WidgetArtifactManifest;

    /**
     * Persist (or idempotently reuse) one PENDING Manifest under
     * `(manifest->projectName, manifest->manifestHash)`. PERSISTING
     * implementations MUST reject a non-pending manifest; no-op
     * implementations (e.g. {@see NullWidgetArtifactManifestRepository})
     * MAY accept and discard it -- this slice never approves or
     * persists an approval decision (Task 15a/15b own that later). An
     * existing row for the same `(project_name, manifest_hash)` is returned
     * UNCHANGED (idempotent reuse); this can never be a "false conflict":
     * two Manifests that differ in ANY canonical field (provider contracts,
     * applicability, generator/renderer identity, ...) necessarily differ
     * in `manifest_hash` and therefore occupy different rows even when they
     * reference the identical Blob (one-Blob-many-Manifests).
     */
    public function putPending(WidgetArtifactManifest $manifest): WidgetArtifactManifest;
}
