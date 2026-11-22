<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

/**
 * Always-empty default ProviderUsageReader -- mirrors the established
 * always-safe-empty default pattern in this module:
 * App\Support\Widget\NullWidgetExtensionRegistrar (Task 7) and
 * Cms\Widget\Rendering\Artifacts\NullWidgetArtifactManifestRepository
 * (Task 10). Every provider version reports unreferenced, so
 * ProviderUsageGuard never blocks an unregister and health() never reports
 * usage drift. This is the correct, safe default before Task 13a/13b's real
 * Layout-backed reader exists: with no Layout tables yet, there is
 * genuinely no usage to report.
 */
final class NullProviderUsageReader implements ProviderUsageReader
{
    public function isReferenced(string $key, int $version): bool
    {
        return false;
    }

    public function referencedProviderVersions(): array
    {
        return [];
    }
}
