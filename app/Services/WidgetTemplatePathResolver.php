<?php

namespace App\Services;

use Illuminate\Support\Str;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

final class WidgetTemplatePathResolver
{
    /**
     * Resolved-path cache keyed by "{generationId}|{projectName}|{filename}".
     * Keying by the observed generation identity means a pointer switch is
     * automatically visible (a new generation id is a new, empty cache
     * bucket); {@see clearCache()} is called explicitly after every
     * successful publish switch as a defensive second guarantee against a
     * stale cache surviving worker reuse (Octane-style long-lived workers).
     *
     * @var array<string, string|null>
     */
    private static array $resolvedPathCache = [];

    public function candidates(int $widgetId, int $themeId): array
    {
        $fileName = "widget_{$widgetId}_theme_{$themeId}.blade.php";
        $project = Str::slug((string) config('ispa-cms.folder_name', 'Default')) ?: 'default';

        return array_values(array_unique([
            storage_path("app/generated_widgets/{$project}/{$fileName}"),
            storage_path("app/generated_widgets/default/{$fileName}"),
            resource_path("views/widgets/{$fileName}"),
            project_path("views/widgets/{$fileName}"),
        ]));
    }

    /**
     * Resolve global pointer -> generation manifest -> selected project set
     * first; fall back to the characterized legacy candidate chain during
     * migration (no pointer/manifest published yet, or the file is not
     * present in the active generation for this project or Default).
     */
    public function resolve(int $widgetId, int $themeId): ?string
    {
        $fileName = "widget_{$widgetId}_theme_{$themeId}.blade.php";

        $viaGeneration = $this->resolveViaActiveGeneration($fileName);
        if ($viaGeneration !== null) {
            return $viaGeneration;
        }

        foreach ($this->candidates($widgetId, $themeId) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Explicitly invalidate the resolver cache. Called by
     * {@see LegacyWidgetArtifactSetPublisher} after a successful pointer
     * switch so no stale resolution can survive within a long-lived worker.
     */
    public static function clearCache(): void
    {
        self::$resolvedPathCache = [];
    }

    private function resolveViaActiveGeneration(string $fileName): ?string
    {
        $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
        if ($manifest === null) {
            return null;
        }

        $activeProjectName = (string) (config('ispa-cms.folder_name', 'Default') ?: 'Default');
        $cacheKey = $manifest->generationId . '|' . $activeProjectName . '|' . $fileName;

        if (array_key_exists($cacheKey, self::$resolvedPathCache)) {
            return self::$resolvedPathCache[$cacheKey];
        }

        $path = LegacyWidgetArtifactSetPublisher::resolveProjectFilePath($manifest, $activeProjectName, $fileName)
            ?? LegacyWidgetArtifactSetPublisher::resolveProjectFilePath($manifest, 'Default', $fileName);

        return self::$resolvedPathCache[$cacheKey] = $path;
    }
}
