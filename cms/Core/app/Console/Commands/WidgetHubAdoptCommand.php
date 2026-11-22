<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Cms\Core\Services\Widget\Catalog\WidgetCloneService;
use Cms\Core\Services\Widget\Hub\WidgetBundle;
use Cms\Core\Services\Widget\Hub\WidgetBundleBuilder;
use Cms\Core\Services\Widget\Hub\WidgetBundleUntrusted;
use Cms\Core\Services\Widget\Hub\WidgetBundleVerifier;
use Cms\Core\Services\Widget\Hub\WidgetHubCatalog;
use Cms\Core\Services\Widget\Hub\WidgetHubException;
use Cms\Core\Services\Widget\Hub\WidgetHubPreviewFiles;
use Cms\Core\Services\Widget\Hub\WidgetResolverRequirements;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Widget hub switch-over (spec C2/C3): every hub widget already on this site is matched against the versions the hub
 * serves for its key.
 *
 * - Content equal to a hub version (same `contentSha256` as {@see WidgetBundleBuilder} computes for the site's rows):
 *   `version` is set to the highest such version.
 * - Content that differs: the row becomes a site copy in place (origin = site, `cloned_from_widget_key` = hub key, key
 *   `site.<hub key>-<n>`, name with the clone suffix), so it keeps its look and never receives updates, and the hub key
 *   is free for "Tải về".
 * - A key the hub does not serve (404, or a key the hub could not hold): kept unchanged and reported ("không có trên
 *   hub — giữ nguyên"), because a 404 can also mean an unpublished or misconfigured widget and a site copy is hard to
 *   undo. `--convert-missing` turns those into site copies too.
 *
 * Only render-relevant content decides the match ({@see self::usableHash()}): widget fields, design content/css/query/
 * name, inputs, assets and requires. Left out on both sides: per-language names; design `project_name` (hub bundles
 * built on local list client projects, sites store the public list; it only filters which designs count); and the
 * editor-only `label` inside input options (added to the catalog on 2026-11-20, never seeded into older sites). Hub
 * inputs whose input_key the site does not have (client-only inputs merged in on local) are ignored, because an update
 * brings them; a site input the hub version lacks still differs.
 *
 * Only designs usable on this site count ({@see WidgetTheme::projectListAllows()} with this site's project folder):
 * hub bundles are built on local with every design, client designs included, while non-local sites seed only their own
 * project's and Default designs. When the full hashes differ, the signed bundles of the hub versions are fetched,
 * verified like a download ({@see WidgetBundleVerifier}), and compared newest first on the hash of their usable designs
 * against the same hash of the site's rows. A bundle that cannot be fetched or does not verify refuses the whole run.
 *
 * The hash uses the row's publisher (iSPA when empty, like cms:widget-bundle-build) and the current renderer version, so
 * run it on the same code that built the hub bundles; after a renderer bump every widget would differ.
 *
 * A stamped widget also takes the hub's non-rendering metadata from the verified bundle it matched: the widget's
 * `name_translations` / `description_translations`, each matching design's `name_translations` and the editor `label`
 * inside each matching input's options (merged; other option keys, content, css, query and js are never touched).
 * Values the bundle lacks are left as they are. Rows already stamped at that version get the same backfill, so a
 * re-run brings names and labels to sites stamped before this existed; it writes nothing when they already agree.
 * When the full hashes match, the site already carries the bundle's names and labels and no bundle is fetched.
 *
 * Preview images: a stamped widget's designs that have no `preview_image` get the bundle's preview PNG for the same
 * theme_key (written like the installer does, temporary name then rename; `preview_stale` false). This also backfills
 * widgets stamped by an earlier run, for which the matched version's bundle is fetched and verified when any design
 * lacks a preview. Designs that already have a preview, and site copies, are never touched.
 *
 * Widget, design and input ids are kept; design files are named by ids and are not regenerated. Blocks that reference
 * the widget by widget_id/template_id are not touched; blocks that reference it by widget_key + theme_key get only
 * their widget_key rewritten to the new key, like a clone does, so every page renders the same. Nothing is deleted.
 * Audit rows: `widget.downloaded` for a stamp that changes the version, `widget.cloned` for a conversion, both with
 * `actor: console` and no user. Every hub answer is read before anything is written; the command refuses to run while
 * the hub is unreachable or answers an empty or malformed version list. Each widget is its own transaction: a failure
 * partway prints the widgets already done and exits 1. Re-running is safe: stamped rows match again, site copies are
 * skipped.
 *
 * Usage: php artisan cms:widget-hub-adopt [--dry-run] [--convert-missing]
 */
class WidgetHubAdoptCommand extends Command
{
    protected $signature = 'cms:widget-hub-adopt
        {--dry-run : Show what would change without writing}
        {--convert-missing : Also turn widgets the hub does not have into site copies (default: keep them unchanged)}';

    protected $description = 'Switch-over: stamp hub widgets with their matching hub version, or turn differing ones into site copies';

    public function handle(
        WidgetBundleBuilder $builder,
        WidgetCloneService $cloneService,
        WidgetHubCatalog $catalog,
        WidgetBundleVerifier $verifier,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $widgets = Widget::query()->where('origin', Widget::ORIGIN_HUB)->orderBy('id')->get();
        if ($widgets->isEmpty()) {
            $this->info('No hub widgets on this site.');

            return self::SUCCESS;
        }

        // Read and compare every hub answer first: an unreachable hub or an untrusted bundle writes nothing.
        $project = (string) (config('ispa-cms.folder_name') ?: 'Default');
        $hubVersions = [];
        $matched = [];
        $bundles = [];
        foreach ($widgets as $widget) {
            $key = (string) $widget->widget_key;
            $versions = $this->hubVersions($key);
            if ($versions === false) {
                $this->error('The widget hub is unreachable; nothing was changed. Run again when it answers.');

                return self::FAILURE;
            }
            $hubVersions[$key] = $versions;
            try {
                [$matched[$key], $bundles[$key]] = $versions === null
                    ? [null, null]
                    : $this->matchingVersion($widget, $versions, $project, $builder, $catalog, $verifier);
                // Equal full hashes fetch no bundle; fetch it when designs lack a preview image to bring over.
                if ($matched[$key] !== null && $bundles[$key] === null && self::lacksPreview($widget)) {
                    $bundles[$key] = $this->fetchVerified($key, $matched[$key], $catalog, $verifier);
                }
            } catch (WidgetHubException|WidgetBundleUntrusted $e) {
                $this->error("The hub bundle of {$key} could not be read or verified ({$e->getMessage()}); nothing was changed.");

                return self::FAILURE;
            }
        }

        $rows = [];
        try {
            $this->apply($widgets, $hubVersions, $matched, $bundles, $cloneService, $dryRun, $rows);
        } catch (Throwable $e) {
            // Widgets before this one are committed (each has its own transaction); show them, then the failure.
            $this->table(['Key', 'Action', 'Version'], $rows);
            $this->error('Stopped at widget ' . (count($rows) + 1) . ': ' . $e->getMessage() . ' Widgets listed above were done; running again is safe.');

            return self::FAILURE;
        }

        $this->table(['Key', 'Action', 'Version'], $rows);
        if ($dryRun) {
            $this->info('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * @param iterable<Widget> $widgets
     * @param array<string, array<int, string>|null> $hubVersions
     * @param array<string, int|null> $matched hub version whose content equals the widget's, by key
     * @param array<string, WidgetBundle|null> $bundles verified bundle of that version, when it had to be fetched
     * @param list<array{string, string, int|string}> $rows filled as each widget is done
     */
    private function apply(iterable $widgets, array $hubVersions, array $matched, array $bundles, WidgetCloneService $cloneService, bool $dryRun, array &$rows): void
    {
        $convertMissing = (bool) $this->option('convert-missing');
        foreach ($widgets as $widget) {
            $key = (string) $widget->widget_key;
            $versions = $hubVersions[$key];
            if ($versions === null && !$convertMissing) {
                $rows[] = [$key, 'không có trên hub — giữ nguyên (not on the hub; --convert-missing makes it a site copy)', (int) $widget->version];

                continue;
            }
            $version = $matched[$key] ?? null;
            if ($version !== null) {
                $bundle = $bundles[$key] ?? null;
                $metadata = $bundle === null ? [] : self::metadataChanges($widget, $bundle);
                $missing = $bundle === null ? [] : self::missingPreviewThemes($widget, $bundle);
                $files = new WidgetHubPreviewFiles();
                if (!$dryRun && ((int) $widget->version !== $version || $metadata !== [] || $missing !== [])) {
                    $written = [];
                    try {
                        $paths = $missing === [] ? [] : $files->write($bundle, $written, array_values($missing));
                        DB::transaction(function () use ($widget, $version, $metadata, $missing, $paths): void {
                        self::writeMetadata($metadata);
                        foreach ($missing as $themeId => $themeKey) {
                            if (isset($paths[$themeKey])) {
                                DB::table('widget_themes')->where('id', $themeId)->where(static fn ($q) => $q->whereNull('preview_image')->orWhere('preview_image', ''))
                                    ->update(['preview_image' => $paths[$themeKey], 'preview_stale' => false, 'updated_at' => now()]);
                            }
                        }
                        if ((int) $widget->version !== $version) {
                            DB::table('widgets')->where('id', $widget->id)->update(['version' => $version, 'updated_at' => now()]);
                            $this->audit(WidgetAuditRecorder::ACTION_DOWNLOADED, $widget, [
                                'version' => $version,
                                'from_version' => (int) $widget->version,
                                'switch_over' => true,
                            ]);
                        }
                        });
                    } catch (Throwable $e) {
                        $files->deleteFiles($written);

                        throw $e;
                    }
                    $files->moveIntoPlace($written);
                }
                $fillable = $bundle === null ? 0 : count(array_filter($bundle->designs, static fn (array $d): bool => $d['preview_png_base64'] !== null && in_array($d['theme_key'], $missing, true)));
                $note = $metadata === [] ? '' : ($dryRun ? '; hub names/labels would be copied' : '; hub names/labels copied');
                $note .= $fillable === 0 ? '' : ($dryRun ? "; {$fillable} preview image(s) would be filled" : "; {$fillable} preview image(s) filled");
                $rows[] = [$key, 'recorded as downloaded' . $note, $version];

                continue;
            }

            $reason = $versions === null ? 'not on the hub' : 'differs from every hub version';
            if ($dryRun) {
                $newKey = $cloneService->nextCloneKey($key);
                $changed = $cloneService->rewriteKeyReferences($key, $newKey, false);
            } else {
                [$newKey, $changed] = $this->convert($widget, $cloneService, $reason);
            }
            $rows[] = [$key, "site copy {$newKey} ({$reason})" . self::describePages($changed, $dryRun), '-'];
        }
    }

    /**
     * Turn a hub widget into a site copy in place; returns its new key and the rows whose blocks were moved to it.
     * The key changes through the query builder because the model refuses key changes; blocks that reference the old
     * key by widget_key + theme_key are rewritten in the same transaction by
     * {@see WidgetCloneService::rewriteKeyReferences()} (only widget_key changes), so they keep resolving to this row.
     *
     * @return array{string, array{posts: list<int|string>, contents: list<int|string>}}
     */
    private function convert(Widget $widget, WidgetCloneService $cloneService, string $reason): array
    {
        return DB::transaction(function () use ($widget, $cloneService, $reason): array {
            // Same lock the clone service holds while it picks a key, so a concurrent clone cannot take it.
            Widget::query()->whereKey($widget->id)->lockForUpdate()->first();
            $hubKey = (string) $widget->widget_key;
            $newKey = $cloneService->nextCloneKey($hubKey);
            DB::table('widgets')->where('id', $widget->id)->update([
                'widget_key' => $newKey,
                'name' => WidgetCloneService::cloneName((string) $widget->name),
                'name_translations' => WidgetNameTranslations::encode(WidgetCloneService::cloneNameTranslations($widget->name_translations)),
                'origin' => Widget::ORIGIN_SITE,
                'publisher' => null,
                'cloned_from_widget_key' => $hubKey,
                'cloned_at' => now(),
                'updated_at' => now(),
            ]);

            $changed = $cloneService->rewriteKeyReferences($hubKey, $newKey);

            $this->audit(WidgetAuditRecorder::ACTION_CLONED, $widget, [
                'hub_key' => $hubKey,
                'clone_key' => $newKey,
                'pages_updated' => count($changed['posts']),
                'switch_over' => true,
                'reason' => $reason,
            ]);

            return [$newKey, $changed];
        });
    }

    /**
     * The row {@see WidgetAuditRecorder} writes, with no user: the switch-over runs from the console and
     * audit_logs.user_id references users (the recorder requires one).
     *
     * @param array<string, mixed> $details
     */
    private function audit(string $action, Widget $widget, array $details): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'target_type' => 'widget',
            'target_id' => $widget->getKey(),
            'user_id' => null,
            'options' => $details + ['actor' => 'console'],
        ]);
    }

    /**
     * @param array{posts: list<int|string>, contents: list<int|string>} $changed
     */
    private static function describePages(array $changed, bool $dryRun): string
    {
        $refs = [
            ...array_map(static fn ($id): string => "post {$id}", $changed['posts']),
            ...array_map(static fn ($id): string => "content {$id}", $changed['contents']),
        ];

        return $refs === [] ? '' : ($dryRun ? '; key would change in ' : '; key changed in ') . implode(', ', $refs);
    }

    /**
     * The highest hub version whose content equals the widget's, counting only designs usable on `$project`, with its
     * verified bundle when one was fetched; [null, null] when none does. The full hashes are compared first (equal means
     * names and labels are equal too, so no bundle is needed); only when none is equal are the signed bundles fetched.
     *
     * @param array<int, string> $versions version => content_sha256
     * @return array{0: int|null, 1: WidgetBundle|null}
     * @throws WidgetHubException|WidgetBundleUntrusted when a bundle cannot be fetched or does not verify
     */
    private function matchingVersion(Widget $widget, array $versions, string $project, WidgetBundleBuilder $builder, WidgetHubCatalog $catalog, WidgetBundleVerifier $verifier): array
    {
        $key = (string) $widget->widget_key;
        // Same publisher fallback as cms:widget-bundle-build, so a row without publisher hashes like its bundle.
        $site = $builder->build($key, 1, (string) ($widget->publisher ?: WidgetCatalogSources::ISPA));
        $equal = array_keys(array_filter($versions, static fn (string $h): bool => hash_equals($h, $site->contentSha256)));
        if ($equal !== []) {
            return [max($equal), null];
        }

        $siteHash = self::usableHash($site, $project);
        $siteInputKeys = array_column($site->inputs, 'input_key');
        krsort($versions);
        foreach (array_keys($versions) as $version) {
            $bundle = $verifier->verify($catalog->signedBundle($key, $version));
            if ($bundle->widgetKey !== $key || $bundle->version !== $version) {
                throw new WidgetBundleUntrusted('bundle is not the requested widget version');
            }
            if (hash_equals(self::usableHash($bundle, $project, $siteInputKeys), $siteHash)) {
                return [$version, $bundle];
            }
        }

        return [null, null];
    }

    /** True when any design of the widget has no preview image. */
    private static function lacksPreview(Widget $widget): bool
    {
        return WidgetTheme::query()->where('widget_id', $widget->id)
            ->where(static fn ($q) => $q->whereNull('preview_image')->orWhere('preview_image', ''))->exists();
    }

    /**
     * Designs without a preview image for which the bundle has a PNG, as design id => theme_key.
     *
     * @return array<int, string>
     */
    private static function missingPreviewThemes(Widget $widget, WidgetBundle $bundle): array
    {
        $withPng = [];
        foreach ($bundle->designs as $design) {
            if ($design['preview_png_base64'] !== null) {
                $withPng[$design['theme_key']] = true;
            }
        }
        $missing = [];
        foreach (WidgetTheme::query()->where('widget_id', $widget->id)->get(['id', 'theme_key', 'preview_image']) as $theme) {
            if (($theme->preview_image === null || $theme->preview_image === '') && isset($withPng[$theme->theme_key])) {
                $missing[(int) $theme->id] = (string) $theme->theme_key;
            }
        }

        return $missing;
    }

    private function fetchVerified(string $key, int $version, WidgetHubCatalog $catalog, WidgetBundleVerifier $verifier): WidgetBundle
    {
        $bundle = $verifier->verify($catalog->signedBundle($key, $version));
        if ($bundle->widgetKey !== $key || $bundle->version !== $version) {
            throw new WidgetBundleUntrusted('bundle is not the requested widget version');
        }

        return $bundle;
    }

    /**
     * Writes that bring the bundle's names and editor labels onto the site rows, as table => id => column => value;
     * empty when they already agree. Only values the bundle has are copied; designs and inputs match by key.
     *
     * @return array<string, array<int, array<string, string|null>>>
     */
    private static function metadataChanges(Widget $widget, WidgetBundle $bundle): array
    {
        $changes = [];
        foreach (['name_translations', 'description_translations'] as $column) {
            $hub = WidgetNameTranslations::normalize($bundle->widget[$column] ?? null);
            if ($hub !== null && $hub !== WidgetNameTranslations::normalize($widget->getAttribute($column))) {
                $changes['widgets'][(int) $widget->id][$column] = WidgetNameTranslations::encode($hub);
            }
        }

        $themes = WidgetTheme::query()->where('widget_id', $widget->id)->get()->keyBy('theme_key');
        foreach ($bundle->designs as $design) {
            $theme = $themes->get($design['theme_key']);
            $hub = WidgetNameTranslations::normalize($design['name_translations'] ?? null);
            if ($theme !== null && $hub !== null && $hub !== WidgetNameTranslations::normalize($theme->name_translations)) {
                $changes['widget_themes'][(int) $theme->id]['name_translations'] = WidgetNameTranslations::encode($hub);
            }
        }

        $inputs = WidgetInput::query()->where('widget_id', $widget->id)->get()->keyBy('input_key');
        foreach ($bundle->inputs as $row) {
            $input = $inputs->get($row['input_key']);
            $options = $input === null ? null : self::optionsWithLabel($input->getRawOriginal('options'), $row['options'] ?? null);
            if ($options !== null) {
                $changes['widget_inputs'][(int) $input->id]['options'] = $options;
            }
        }

        return $changes;
    }

    /**
     * Site options with the hub's `label`, or null when there is nothing to change. When the rest of the options equal
     * the hub's, the hub's exact text is stored (so the rows hash like the bundle); otherwise only `label` is merged in.
     * Options that are not a JSON object (or empty) on either side are left alone.
     */
    private static function optionsWithLabel(?string $site, ?string $hub): ?string
    {
        $hubDecoded = $hub === null ? null : json_decode($hub, true);
        if (!is_array($hubDecoded) || !array_key_exists('label', $hubDecoded)) {
            return null;
        }
        $siteDecoded = $site === null || trim($site) === '' ? [] : json_decode($site, true);
        if (!is_array($siteDecoded) || ($siteDecoded !== [] && array_is_list($siteDecoded))) {
            return null;
        }
        if (array_key_exists('label', $siteDecoded) && $siteDecoded['label'] === $hubDecoded['label']) {
            return null;
        }
        $hubRest = $hubDecoded;
        $siteRest = $siteDecoded;
        unset($hubRest['label'], $siteRest['label']);
        if ($hubRest == $siteRest) {
            return $hub;
        }
        $siteDecoded['label'] = $hubDecoded['label'];

        return json_encode($siteDecoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, array<int, array<string, string|null>>> $changes from {@see self::metadataChanges()}
     */
    private static function writeMetadata(array $changes): void
    {
        foreach ($changes as $table => $byId) {
            foreach ($byId as $id => $values) {
                DB::table($table)->where('id', $id)->update($values + ['updated_at' => now()]);
            }
        }
    }

    /**
     * Content hash of `$bundle` with only the designs usable on `$project`; resolver requirements follow those designs.
     *
     * Per-language names (`name_translations`, `description_translations`) are left out on both sides: a site row
     * whose only difference from a hub version is its translated names (the published v1 bundles carry none) is that
     * version, not a site copy. They stay in the bundle's own `contentSha256`, so updates still verify them.
     *
     * Also left out, because none affects rendering: each kept design's `project_name` (used only to pick the usable
     * designs), the editor-only `label` inside input options, and, when `$inputKeys` is given (the hub side), inputs
     * whose input_key is not in it.
     *
     * @param list<string>|null $inputKeys input_keys the site has; null keeps every input
     */
    private static function usableHash(WidgetBundle $bundle, string $project, ?array $inputKeys = null): string
    {
        $designs = array_values(array_map(
            static function (array $design): array {
                $design = WidgetBundle::withoutTranslations($design);
                unset($design['project_name']);

                return $design;
            },
            array_filter(
                $bundle->designs,
                static fn (array $design): bool => WidgetTheme::projectListAllows(self::projectList($design['project_name'] ?? null), $project),
            ),
        ));
        $inputs = array_values(array_map(
            static fn (array $input): array => ['options' => self::renderOptions($input['options'] ?? null)] + $input,
            array_filter(
                $bundle->inputs,
                static fn (array $input): bool => $inputKeys === null || in_array($input['input_key'], $inputKeys, true),
            ),
        ));

        return WidgetBundle::create(
            widgetKey: $bundle->widgetKey,
            version: 1,
            publisher: $bundle->publisher,
            widget: WidgetBundle::withoutTranslations($bundle->widget),
            designs: $designs,
            inputs: $inputs,
            assets: $bundle->assets,
            requires: ['renderer' => $bundle->requires['renderer'], 'resolvers' => WidgetResolverRequirements::forDesigns($designs)],
        )->contentSha256;
    }

    /**
     * Input options without the editor-only `label`, re-encoded the same way on both sides; empty options read as null.
     * Options that are not a JSON object or list are kept as stored.
     */
    private static function renderOptions(?string $options): ?string
    {
        $decoded = $options === null ? null : json_decode($options, true);
        if (!is_array($decoded)) {
            return $options;
        }
        unset($decoded['label']);

        return $decoded === [] ? null : json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A project list stored twice JSON-encoded (a string holding a JSON list) read as that list, so such a row is not
     * filtered out on both sides and matched on nothing.
     */
    private static function projectList(mixed $projectName): mixed
    {
        if (is_string($projectName) && str_starts_with(ltrim($projectName), '[')) {
            $decoded = json_decode($projectName, true);

            return is_array($decoded) ? $decoded : $projectName;
        }

        return $projectName;
    }

    /**
     * Hub versions of one key as version => content_sha256; null when the hub does not serve the key (404);
     * false when the hub cannot be read.
     *
     * @return array<int, string>|null|false
     */
    private function hubVersions(string $widgetKey): array|null|false
    {
        if (preg_match(WidgetHubCatalog::KEY_PATTERN, $widgetKey) !== 1) {
            return null;
        }
        try {
            $payload = app(PlatformHubClient::class)->get(WidgetHubCatalog::PATH . '/' . $widgetKey . '/versions');
        } catch (PlatformHubException $e) {
            return $e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404 ? null : false;
        }
        if (!is_array($payload['versions'] ?? null)) {
            return false;
        }

        $versions = [];
        foreach ($payload['versions'] as $row) {
            if (!is_array($row) || !is_int($row['version'] ?? null) || !is_string($row['content_sha256'] ?? null)) {
                return false;
            }
            $versions[$row['version']] = $row['content_sha256'];
        }

        // An empty or malformed list is not "the hub lacks this widget": refuse the run rather than guess.
        return $versions === [] ? false : $versions;
    }
}
