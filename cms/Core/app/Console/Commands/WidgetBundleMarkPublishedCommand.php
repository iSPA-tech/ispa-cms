<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Cms\Core\Services\Widget\Hub\WidgetBundleBuilder;
use Cms\Core\Services\Widget\Hub\WidgetBundleUntrusted;
use Cms\Core\Services\Widget\Hub\WidgetBundleVerifier;
use Cms\Core\Services\Widget\Hub\WidgetPublishLedger;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Confirms that the hub accepted a signed widget bundle (`cms:platform-widget-publish` succeeded there): the pending
 * entry of the local publish ledger becomes published, so the next `cms:export-widgets` records it as the widget's
 * `hub_version` / `hub_content_sha256`.
 *
 * Authoring environments only. The sig file is verified with the trusted keys first, and it must be exactly the
 * pending bundle (same key, version and content hash).
 *
 * The local widget row's `version` becomes the published version when its current content still hashes to the
 * bundle's content hash (the same rule `cms:widget-hub-adopt` uses), so the library shows no "Có bản mới" for what
 * this copy just published. A widget edited after signing keeps its version and the command says so.
 */
class WidgetBundleMarkPublishedCommand extends Command
{
    protected $signature = 'cms:widget-bundle-mark-published {sig : Path to the <bundle>.sig.json the hub accepted}';

    protected $description = 'Record that the hub accepted a signed widget bundle.';

    public function handle(WidgetAuthoringPolicy $policy, WidgetPublishLedger $ledger): int
    {
        if (!$policy->authoringEnabled()) {
            return $this->refuse('Widget authoring is off (WIDGET_CATALOG_AUTHORING); publishes are recorded on a local authoring copy only.');
        }

        $path = (string) $this->argument('sig');
        if (!is_file($path) || !is_readable($path)) {
            return $this->refuse('Signature file not found: ' . $path);
        }

        try {
            $bundle = app(WidgetBundleVerifier::class)->verify((string) file_get_contents($path));
        } catch (WidgetBundleUntrusted $e) {
            return $this->refuse('Signature file is not valid: ' . $e->getMessage());
        }

        try {
            $ledger->markPublished($bundle);
        } catch (RuntimeException $e) {
            return $this->refuse($e->getMessage());
        }

        $this->info("Recorded {$bundle->widgetKey} version {$bundle->version} as published. Run cms:export-widgets to write it into the catalog.");
        $this->stampLocalVersion($bundle->widgetKey, $bundle->version, $bundle->publisher, $bundle->contentSha256);

        return self::SUCCESS;
    }

    private function stampLocalVersion(string $widgetKey, int $version, string $publisher, string $contentSha256): void
    {
        $widget = Widget::query()->where('widget_key', $widgetKey)->first();
        if ($widget === null) {
            $this->warn("No local widget {$widgetKey}; its version was not changed.");

            return;
        }
        try {
            $localSha = app(WidgetBundleBuilder::class)->build($widgetKey, $version, $publisher)->contentSha256;
        } catch (\Throwable $e) {
            $this->warn("Could not hash the local {$widgetKey} ({$e->getMessage()}); its version was not changed.");

            return;
        }
        if (!hash_equals($contentSha256, $localSha)) {
            $this->warn("The local {$widgetKey} changed after signing; its version stays {$widget->version}.");

            return;
        }
        if ((int) $widget->version < $version) {
            $widget->forceFill(['version' => $version])->save();
        }
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
