<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetBundleBuilder;
use Cms\Core\Services\Widget\Hub\WidgetPreviewCapture;
use Cms\Core\Services\Widget\Hub\WidgetPublishLedger;
use Cms\Core\Support\Widget\WidgetChangeNotes;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds the unsigned hub bundle of one local widget: `<out>/<widget_key>-<version>.bundle.json` (canonical JSON).
 *
 * Authoring environments only. The version defaults to CalVer `YYYY.MM.NN` stored as the integer YYYYMMNN (app timezone, NN = build of the month, 01-99), or one above the
 * exported `hub_version` and any version signed here since when that is already this month's; the hub refuses any version
 * not above its latest (spec C5). Unless `--no-preview`, each design is rendered under a project it belongs to (default
 * input values, that project's theme CSS) and captured to a PNG by {@see WidgetPreviewCapture}; a design that renders
 * empty or captures blank fails the build, except a design made for one project alone that cannot render here, or one
 * named with `--no-preview-for` (the site's theme draws it), which ships without a preview (warning).
 *
 * When the publisher's `widget-changelogs.php` ({@see WidgetChangeNotes}) has notes for the built version, they are
 * written next to the bundle as the unsigned sidecar `<bundle>.notes.json`, which `cms:platform-widget-publish` picks
 * up. `--notes-only` writes just that sidecar for a version that is already built or published (hub backfill).
 */
class WidgetBundleBuildCommand extends Command
{
    protected $signature = 'cms:widget-bundle-build {widget_key : Widget key of a widget in the local database} {--hub-version= : Bundle version, must exceed the current one (default: automatic CalVer YYYY.MM.NN stored as YYYYMMNN, e.g. 20261101, or the current version + 1 when that is already this month\'s; named so because the console owns --version)} {--publisher= : Publisher (default: the widget\'s publisher)} {--out= : Output directory (default storage/app/widget-bundles)} {--no-preview : Skip the preview image capture} {--no-preview-for=* : Key of a design the site\'s theme draws (it calls a theme component), which ships without a preview when it cannot render here; repeatable} {--notes-only : Only write <bundle>.notes.json of --hub-version from widget-changelogs.php (no bundle, no version checks)}';

    protected $description = 'Build the unsigned hub bundle of a local widget.';

    public function handle(WidgetAuthoringPolicy $policy, WidgetBundleBuilder $builder, WidgetPublishLedger $ledger): int
    {
        if ($this->option('notes-only')) {
            return $this->notesOnly();
        }
        if (!$policy->authoringEnabled()) {
            return $this->refuse('Widget authoring is off (WIDGET_CATALOG_AUTHORING); bundles are built on a local authoring copy only.');
        }

        $key = (string) $this->argument('widget_key');
        $widget = Widget::query()->where('widget_key', $key)->first();
        if ($widget === null) {
            return $this->refuse('No widget with key ' . $key . ' in the local database.');
        }
        $publisher = trim((string) ($this->option('publisher') ?: ($widget->publisher ?: WidgetCatalogSources::ISPA)));

        $versionOption = $this->option('hub-version');
        // Past the exported version and any version signed here since (pending or published).
        $current = max(
            WidgetCatalogSources::hubMetadata($publisher)[$key]['hub_version'] ?? 0,
            $ledger->latestVersion($key),
        );
        if ($versionOption === null || $versionOption === '') {
            $base = (int) now()->format('Ym') * 100 + 1;
            $version = $current >= $base ? $current + 1 : $base;
            if ($version % 100 === 0) {
                return $this->refuse('Already 99 builds this month (version ' . $current . '); pass --hub-version to override.');
            }
        } elseif (preg_match('/\A[1-9]\d{0,9}\z/', (string) $versionOption) === 1 && (int) $versionOption <= 2147483647) {
            $version = (int) $versionOption;
            // Rebuilding a version that is only signed (pending, not yet accepted by the hub) stays allowed.
            $accepted = max(
                WidgetCatalogSources::hubMetadata($publisher)[$key]['hub_version'] ?? 0,
                (int) ($ledger->published()[$key]['hub_version'] ?? 0),
            );
            if ($version <= $accepted) {
                return $this->refuse('--hub-version ' . $version . ' must be greater than the current version ' . $accepted . '.');
            }
        } else {
            return $this->refuse('--hub-version must be a whole number of at least 1.');
        }

        try {
            // Checked before the slow preview capture: a broken changelog fails the build here.
            $notes = WidgetChangeNotes::changelog($publisher)[$key][$version] ?? null;
        } catch (Throwable $e) {
            return $this->refuse('Changelog invalid: ' . $e->getMessage());
        }

        $out = $this->outDirectory();
        if ($out === null) {
            return self::FAILURE;
        }

        try {
            $previews = $this->option('no-preview') ? [] : (new WidgetPreviewCapture(fn (string $message) => $this->warn($message)))->captureAll($widget, $publisher, $out, array_map('strval', (array) $this->option('no-preview-for')));
            $json = $builder->build($key, $version, $publisher, $previews)->toCanonicalJson();
            // Parse our own output back: a widget the bundle format rejects fails here, not at sign time.
            $bundle = WidgetBundle::fromCanonicalJson($json);
        } catch (Throwable $e) {
            return $this->refuse('Build failed: ' . $e->getMessage());
        }

        $path = $out . '/' . $key . '-' . $version . '.bundle.json';
        // A signature of an earlier build of this version signs other bytes; never leave it next to the new bundle.
        $sigPath = $path . '.sig.json';
        if (file_exists($sigPath) || is_link($sigPath)) {
            if (!@unlink($sigPath)) {
                return $this->refuse('Cannot remove the old signature ' . $sigPath . '; nothing written.');
            }
            $this->warn('Removed the old signature of this version (it signed an earlier build): ' . $sigPath);
        }
        if (file_put_contents($path, $json) !== strlen($json)) {
            @unlink($path);

            return $this->refuse('Writing ' . $path . ' failed; nothing kept.');
        }

        // Notes of an earlier build of this version may be stale; the sidecar always follows the current changelog.
        $notesPath = $path . WidgetChangeNotes::SIDECAR_SUFFIX;
        if ($notes === null) {
            if (is_file($notesPath) && !@unlink($notesPath)) {
                $this->warn('Cannot remove the old notes ' . $notesPath . '; delete it before publishing.');
            }
        } elseif (!$this->writeNotes($notesPath, $key, $version, $notes)) {
            return self::FAILURE;
        }

        $this->info("Built {$key} version {$version} ({$publisher}, " . count($bundle->designs) . ' designs, ' . count($previews) . " previews): {$path}");
        if ($notes !== null) {
            $this->info('What-changed notes (' . count($notes) . ' items): ' . $notesPath);
        }
        $this->line('Next: php artisan cms:widget-bundle-sign ' . $path . ' --key-file=~/.ispa-release-keys/release-signing.key');

        return self::SUCCESS;
    }

    /**
     * `--notes-only`: write the sidecar of `--hub-version` from the changelog, nothing else.
     */
    private function notesOnly(): int
    {
        $key = (string) $this->argument('widget_key');
        $versionOption = (string) $this->option('hub-version');
        if (preg_match('/\A[1-9]\d{0,9}\z/', $versionOption) !== 1 || (int) $versionOption > 2147483647) {
            return $this->refuse('--notes-only needs --hub-version (the version the notes belong to).');
        }
        $version = (int) $versionOption;
        $publisher = trim((string) ($this->option('publisher') ?: (Widget::query()->where('widget_key', $key)->value('publisher') ?: WidgetCatalogSources::ISPA)));
        try {
            $notes = WidgetChangeNotes::changelog($publisher)[$key][$version] ?? null;
        } catch (Throwable $e) {
            return $this->refuse('Changelog invalid: ' . $e->getMessage());
        }
        if ($notes === null) {
            return $this->refuse('No notes for ' . $key . ' version ' . $version . ' in ' . WidgetChangeNotes::FILE . ' of ' . $publisher . '.');
        }
        $out = $this->outDirectory();
        if ($out === null) {
            return self::FAILURE;
        }
        $path = $out . '/' . $key . '-' . $version . '.bundle.json' . WidgetChangeNotes::SIDECAR_SUFFIX;
        if (!$this->writeNotes($path, $key, $version, $notes)) {
            return self::FAILURE;
        }
        $this->info('What-changed notes (' . count($notes) . ' items): ' . $path);

        return self::SUCCESS;
    }

    private function outDirectory(): ?string
    {
        $out = rtrim((string) ($this->option('out') ?: storage_path('app/widget-bundles')), '/');
        if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
            $this->refuse('Cannot create ' . $out);

            return null;
        }

        return $out;
    }

    /**
     * @param list<array{type: string, vi: string, en: string}> $notes
     */
    private function writeNotes(string $path, string $key, int $version, array $notes): bool
    {
        $json = WidgetChangeNotes::encodeSidecar($key, $version, $notes);
        if (file_put_contents($path, $json) !== strlen($json)) {
            @unlink($path);
            $this->refuse('Writing ' . $path . ' failed.');

            return false;
        }

        return true;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
