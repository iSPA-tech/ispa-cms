<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use RuntimeException;

/**
 * Local record of widget bundles sent to the hub, per widget key (spec C5).
 *
 * `cms:widget-bundle-sign` records the signed version and content hash as `pending`. Publishing happens on the hub,
 * which this site cannot see, so the owner runs `cms:widget-bundle-mark-published {sig}` once the hub accepted it;
 * that moves the entry to `published`. `cms:export-widgets` copies only published entries into the exported widget
 * row (`hub_version`, `hub_content_sha256`) and names pending ones. The file lives outside the tracked catalog
 * (default `storage/app/widget-hub/published.json`, overridable through `ispa-cms.widget_hub.publish_ledger`).
 *
 * Shape: `widget_key => {pending?: Entry, published?: Entry}`, Entry = `{hub_version, hub_content_sha256, publisher}`.
 */
final class WidgetPublishLedger
{
    public function path(): string
    {
        return (string) (config('ispa-cms.widget_hub.publish_ledger') ?: storage_path('app/widget-hub/published.json'));
    }

    /** @return array<string, array{pending?: array{hub_version: int, hub_content_sha256: string, publisher: string}, published?: array{hub_version: int, hub_content_sha256: string, publisher: string}}> */
    public function all(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** @return array<string, array{hub_version: int, hub_content_sha256: string, publisher: string}> */
    public function published(): array
    {
        return array_filter(array_map(static fn (array $e): ?array => $e['published'] ?? null, $this->all()));
    }

    /** @return array<string, array{hub_version: int, hub_content_sha256: string, publisher: string}> */
    public function pending(): array
    {
        return array_filter(array_map(static fn (array $e): ?array => $e['pending'] ?? null, $this->all()));
    }

    /** Highest version recorded for a key, pending or published (0 when none). */
    public function latestVersion(string $widgetKey): int
    {
        $entry = $this->all()[$widgetKey] ?? [];

        return max((int) ($entry['pending']['hub_version'] ?? 0), (int) ($entry['published']['hub_version'] ?? 0));
    }

    /** Record a signed bundle as pending (replaces an older pending entry). */
    public function recordSigned(WidgetBundle $bundle): void
    {
        $entries = $this->all();
        $entries[$bundle->widgetKey]['pending'] = self::entry($bundle);
        $this->write($entries);
    }

    /**
     * Mark the pending bundle as published on the hub. Refuses unless the pending entry is exactly this bundle.
     *
     * @throws RuntimeException
     */
    public function markPublished(WidgetBundle $bundle): void
    {
        $entries = $this->all();
        $pending = $entries[$bundle->widgetKey]['pending'] ?? null;
        if ($pending !== self::entry($bundle)) {
            throw new RuntimeException(sprintf(
                'The pending bundle of %s is %s, not version %d with this content; sign the bundle you published first.',
                $bundle->widgetKey,
                $pending === null ? 'none' : 'version ' . $pending['hub_version'],
                $bundle->version,
            ));
        }
        $entries[$bundle->widgetKey] = ['published' => $pending];
        $this->write($entries);
    }

    /** @return array{hub_content_sha256: string, hub_version: int, publisher: string} */
    private static function entry(WidgetBundle $bundle): array
    {
        return ['hub_content_sha256' => $bundle->contentSha256, 'hub_version' => $bundle->version, 'publisher' => $bundle->publisher];
    }

    private function write(array $entries): void
    {
        ksort($entries);
        $path = $this->path();
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create ' . dirname($path));
        }
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Writing ' . $path . ' failed.');
        }
    }
}
