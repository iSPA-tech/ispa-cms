<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Artifacts;

/**
 * Always-empty no-op WidgetArtifactManifestRepository: the only production
 * implementation in this persistence-agnostic slice, and the INTENDED
 * default binding once a consumer wires one (Task 15a). No container
 * binding exists yet -- WidgetServiceProvider currently binds only the
 * root WidgetRenderer contract. Mirrors the established Null-object
 * precedent in this codebase (App\Support\Widget\NullWidgetSlotRuntime,
 * App\Support\Widget\NullWidgetExtensionRegistrar,
 * App\Support\Achievements\NullAchievementRecorder/NullAchievementReader):
 * always-empty reads, harmless no-op writes, never throws.
 *
 * `findByProjectAndManifestHash()` always misses (null) and `putPending()`
 * silently accepts and returns its argument unchanged without persisting
 * anything (as a no-op implementation it MAY accept even a non-pending
 * manifest and discard it; only PERSISTING implementations must reject) --
 * a caller holding this implementation (module disabled, or no real
 * repository wired yet) never observes a thrown exception merely for
 * calling these methods.
 */
final class NullWidgetArtifactManifestRepository implements WidgetArtifactManifestRepository
{
    public function findByProjectAndManifestHash(string $projectName, string $manifestHash): ?WidgetArtifactManifest
    {
        return null;
    }

    public function putPending(WidgetArtifactManifest $manifest): WidgetArtifactManifest
    {
        return $manifest;
    }
}
