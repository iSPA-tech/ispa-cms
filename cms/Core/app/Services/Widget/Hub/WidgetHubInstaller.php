<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Cms\Core\Services\Widget\Catalog\WidgetUsageService;
use Cms\Core\Services\Widget\DTO\WidgetDefinitionSyncResult;
use Cms\Core\Services\Widget\WidgetDefinitionSyncService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Downloads a hub widget into this site, or updates a downloaded hub widget to the hub's latest version.
 *
 * Each download/update of one widget key runs under an atomic cache lock ({@see self::lockName()}), so a second press
 * waits and then sees the first one's result: WIDGET_KEY_TAKEN for a download, WIDGET_UP_TO_DATE for an update (also
 * when the lock cannot be had in time). A unique-key violation or deadlock from a writer outside the lock maps to the
 * same codes, never a 500.
 *
 * Order: hub version → signed bundle → {@see WidgetBundleVerifier} (trusted, not revoked, canonical, hashes) → the
 * bundle is the requested key and version, and newer than the installed one (an older signed bundle still verifies,
 * so this refuses replays) → resolver check → preview/asset files written to request-unique temporary names → one
 * transaction with a locked read of the key: widget row (`origin=hub`, publisher, version), designs matched by
 * `theme_key` (row ids kept, page blocks and area placements keep pointing at them), inputs matched by `input_key`
 * (ids kept; removed ones deleted, their stored values stay on pages and the renderer ignores them) → temporary files
 * moved to their versioned names and design files regenerated after commit → audit. A refused step writes nothing; a
 * request only ever deletes the temporary files it wrote itself, never another request's files.
 *
 * Block state is never touched: a blocked widget stays blocked. Site copies (`origin=site`) never update. The local
 * authoring copy ({@see WidgetAuthoringPolicy::authoringEnabled()}) publishes hub widgets and refuses every download
 * and update with WIDGET_AUTHORING_COPY, so a hub version never overwrites unpublished authored edits.
 */
class WidgetHubInstaller
{
    public const PREVIEW_DIR = 'widget-previews';

    public const ASSET_DIR = 'widget-assets';

    /** Lock lifetime; covers the hub fetches (client timeout 15 s each) and the transaction. */
    public const LOCK_SECONDS = 120;

    private ?WidgetDefinitionSyncResult $lastSync = null;

    /** Cache lock name of one widget key (shared with anything else that installs hub widgets). */
    public static function lockName(string $widgetKey): string
    {
        return 'widget-hub:install:' . $widgetKey;
    }

    public function __construct(
        private readonly WidgetHubCatalog $catalog,
        private readonly WidgetBundleVerifier $verifier,
        private readonly WidgetResolverInventory $inventory,
        private readonly WidgetUsageService $usage,
        private readonly WidgetAuditRecorder $audit,
        private readonly WidgetDefinitionSyncService $sync,
        private readonly WidgetAuthoringPolicy $authoring,
        private readonly WidgetHubPreviewFiles $files = new WidgetHubPreviewFiles(),
    ) {
    }

    /**
     * @throws WidgetHubException|WidgetBundleUntrusted
     */
    public function download(string $widgetKey, int $actorUserId): Widget
    {
        $this->refuseOnAuthoringCopy();

        return $this->locked($widgetKey, static fn (): WidgetHubException => WidgetHubException::keyTaken(), function () use ($widgetKey, $actorUserId): Widget {
            return $this->downloadLocked($widgetKey, $actorUserId);
        });
    }

    private function downloadLocked(string $widgetKey, int $actorUserId): Widget
    {
        if (Widget::query()->where('widget_key', $widgetKey)->exists()) {
            throw WidgetHubException::keyTaken();
        }
        $version = $this->catalog->latestVersion($widgetKey);
        $bundle = $this->fetchVerified($widgetKey, $version);

        return $this->install($bundle, null, $actorUserId);
    }

    /**
     * @throws WidgetHubException|WidgetBundleUntrusted
     */
    public function update(int $widgetId, int $actorUserId): Widget
    {
        $key = (string) $this->updatable($widgetId)->widget_key;

        return $this->locked($key, static fn (): WidgetHubException => WidgetHubException::upToDate(), function () use ($widgetId, $actorUserId): Widget {
            // Read again under the lock: a request that waited sees the version the winner installed.
            $widget = $this->updatable($widgetId);

            return $this->updateLocked($widget, $actorUserId);
        });
    }

    private function updateLocked(Widget $widget, int $actorUserId): Widget
    {
        $version = $this->catalog->latestVersion((string) $widget->widget_key);
        if ($version <= (int) $widget->version) {
            throw WidgetHubException::upToDate();
        }
        $bundle = $this->fetchVerified((string) $widget->widget_key, $version);

        return $this->install($bundle, $widget, $actorUserId);
    }

    /**
     * The latest hub version of a downloaded hub widget, verified and newer than installed (for the impact dialog).
     *
     * @throws WidgetHubException|WidgetBundleUntrusted
     */
    public function latestBundleFor(Widget $widget): WidgetBundle
    {
        $version = $this->catalog->latestVersion((string) $widget->widget_key);
        if ($version <= (int) $widget->version) {
            throw WidgetHubException::upToDate();
        }

        return $this->fetchVerified((string) $widget->widget_key, $version);
    }

    /**
     * A hub widget on this site that may receive hub updates.
     *
     * @throws WidgetHubException
     */
    public function updatable(int $widgetId): Widget
    {
        $this->refuseOnAuthoringCopy();
        $widget = $widgetId > 0 ? Widget::query()->find($widgetId) : null;
        if ($widget === null) {
            throw WidgetHubException::notFound();
        }
        if (!$widget->isHub()) {
            throw WidgetHubException::siteCopy();
        }

        return $widget;
    }

    /**
     * @throws WidgetHubException on the local authoring copy
     */
    private function refuseOnAuthoringCopy(): void
    {
        if ($this->authoring->authoringEnabled()) {
            throw WidgetHubException::authoringCopy();
        }
    }

    /**
     * Theme keys of the installed widget's designs in use that the bundle no longer has.
     *
     * @return list<string> design names (theme_key when unnamed)
     */
    public function removedDesignsInUse(Widget $widget, WidgetBundle $bundle): array
    {
        $kept = array_column($bundle->designs, 'theme_key');
        $inUse = $this->usage->themeIdsInUse($widget);

        return $widget->template()->whereIn('id', $inUse)->orderBy('theme_key')->get(['id', 'theme_key', 'name', 'name_translations'])
            ->reject(static fn (WidgetTheme $t): bool => in_array((string) $t->theme_key, $kept, true))
            ->map(static fn (WidgetTheme $t): string => $t->name ? $t->localizedName() : (string) $t->theme_key)
            ->values()->all();
    }

    /**
     * Run `$work` holding the widget key's lock; when the lock cannot be had in time, throw `$busy()`.
     *
     * @template T
     * @param callable(): WidgetHubException $busy
     * @param callable(): T $work
     * @return T
     */
    private function locked(string $widgetKey, callable $busy, callable $work): mixed
    {
        $lock = Cache::lock(self::lockName($widgetKey), self::LOCK_SECONDS);
        try {
            $lock->block(max(0, (int) config('ispa-cms.widget.hub_lock_wait_seconds', 10)));
        } catch (LockTimeoutException) {
            throw $busy();
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /** Sync status of the last download/update (null before one ran). */
    public function lastSync(): ?WidgetDefinitionSyncResult
    {
        return $this->lastSync;
    }

    private function fetchVerified(string $widgetKey, int $version): WidgetBundle
    {
        $bundle = $this->verifier->verify($this->catalog->signedBundle($widgetKey, $version));
        if ($bundle->widgetKey !== $widgetKey || $bundle->version !== $version) {
            throw new WidgetBundleUntrusted('bundle is not the requested widget version');
        }
        if ($this->inventory->missing($bundle->requires) !== []) {
            throw WidgetHubException::resolverMissing();
        }

        return $bundle;
    }

    private function install(WidgetBundle $bundle, ?Widget $expected, int $actorUserId): Widget
    {
        // final path => this request's temporary path
        $written = [];
        try {
            $previews = $this->files->write($bundle, $written);
            $this->writeAssets($bundle, $written);

            [$widget, $from, $projects] = DB::transaction(function () use ($bundle, $expected, $previews): array {
                $locked = Widget::query()->where('widget_key', $bundle->widgetKey)->lockForUpdate()->first();
                if ($expected === null && $locked !== null) {
                    throw WidgetHubException::keyTaken();
                }
                if ($expected !== null) {
                    if ($locked === null || (int) $locked->id !== (int) $expected->id) {
                        throw WidgetHubException::notFound();
                    }
                    if (!$locked->isHub()) {
                        throw WidgetHubException::siteCopy();
                    }
                    if ($bundle->version <= (int) $locked->version) {
                        throw WidgetHubException::upToDate();
                    }
                    if ($this->removedDesignsInUse($locked, $bundle) !== []) {
                        throw WidgetHubException::designInUse();
                    }
                }

                return $this->writeRows($bundle, $locked, $previews);
            });
        } catch (QueryException $e) {
            $this->files->deleteFiles($written);
            // 23000: unique key taken by a writer outside the lock; 40001: deadlock / serialization failure.
            if (in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['23000', '40001'], true)) {
                throw $expected === null ? WidgetHubException::keyTaken($e) : WidgetHubException::upToDate();
            }
            throw $e;
        } catch (Throwable $e) {
            $this->files->deleteFiles($written);
            throw $e;
        }
        $this->files->moveIntoPlace($written);

        $this->lastSync = $this->sync->sync($projects);
        $this->audit->record(
            $expected === null ? WidgetAuditRecorder::ACTION_DOWNLOADED : WidgetAuditRecorder::ACTION_UPDATED,
            $widget,
            $actorUserId,
            array_filter(['version' => $bundle->version, 'from_version' => $from], static fn ($v): bool => $v !== null),
        );

        return $widget;
    }

    /**
     * @param array<string, string> $previews theme_key => public disk path
     * @return array{0: Widget, 1: ?int, 2: list<string>} widget, previous version, affected projects
     */
    private function writeRows(WidgetBundle $bundle, ?Widget $widget, array $previews): array
    {
        $from = $widget === null ? null : (int) $widget->version;
        $widget ??= new Widget();
        $widget->forceFill([
            'widget_key' => $bundle->widgetKey,
            'name' => $bundle->widget['name'],
            'description' => $bundle->widget['description'],
            'js' => $bundle->widget['js'],
            // A bundle without translations (every v1 bundle) clears them: the bundle is the whole hub content.
            'name_translations' => $bundle->widget['name_translations'] ?? null,
            'description_translations' => $bundle->widget['description_translations'] ?? null,
            'origin' => Widget::ORIGIN_HUB,
            'publisher' => $bundle->publisher,
            'version' => $bundle->version,
        ])->save();

        $projects = [];
        $existing = $widget->template()->get()->keyBy('theme_key');
        foreach ($existing as $theme) {
            $projects = [...$projects, ...self::projectNames($theme->project_name)];
        }
        foreach ($bundle->designs as $design) {
            $theme = $existing->get($design['theme_key']) ?? new WidgetTheme();
            $theme->forceFill([
                'widget_id' => $widget->id,
                'theme_key' => $design['theme_key'],
                'name' => $design['name'],
                'name_translations' => $design['name_translations'] ?? null,
                'content' => $design['content'],
                'css' => $design['css'],
                'query' => $design['query'],
                'project_name' => $design['project_name'],
                'preview_image' => $previews[$design['theme_key']] ?? null,
                'preview_stale' => false,
            ])->save();
            $projects = [...$projects, ...self::projectNames($design['project_name'])];
        }
        $widget->template()->whereNotIn('theme_key', array_column($bundle->designs, 'theme_key'))->delete();

        $inputs = $widget->inputs()->get()->keyBy('input_key');
        foreach ($bundle->inputs as $row) {
            $input = $inputs->get($row['input_key']) ?? new WidgetInput();
            $input->forceFill([
                'widget_id' => $widget->id,
                'input_key' => $row['input_key'],
                'name' => $row['name'],
                'type' => $row['type'],
                'default_value' => $row['default_value'],
            ])->save();
            // `options` travels as the raw column string: store it as is, not through the json cast.
            DB::table($input->getTable())->where('id', $input->id)->update(['options' => $row['options']]);
        }
        $widget->inputs()->whereNotIn('input_key', array_column($bundle->inputs, 'input_key'))->delete();

        return [$widget->fresh(), $from, array_values(array_unique($projects))];
    }

    /**
     * Same fan-out as the legacy generator: a missing or scalar project list means Default.
     *
     * @return list<string>
     */
    private static function projectNames(mixed $value): array
    {
        if (!is_array($value)) {
            return is_string($value) && $value !== '' ? [$value] : ['Default'];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param array<string, string> $written final path => temporary path
     */
    private function writeAssets(WidgetBundle $bundle, array &$written): void
    {
        foreach ($bundle->assets as $asset) {
            // Paths are allowlisted by WidgetBundle (relative, no dot segments, static file types).
            $path = self::ASSET_DIR . '/' . self::safeName($bundle->widgetKey) . '/v' . $bundle->version . '/' . $asset['path'];
            $written[$path] = $this->files->putTemporary($path, (string) base64_decode($asset['base64'], true));
        }
    }

    /**
     * File-name-safe form of a widget or theme key (also used by WidgetPreviewImages): other characters become `-`;
     * a result that still is not an identifier (`..`, `.x`, empty) becomes a hash, so it is never a dot segment.
     */
    public static function safeName(string $value): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $value);

        return preg_match(WidgetHubCatalog::KEY_PATTERN, $name) === 1 ? $name : 'x-' . sha1($value);
    }
}
