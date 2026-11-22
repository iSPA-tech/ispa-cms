<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\Catalog;

use Cms\Core\Support\Widget\WidgetNameTranslations;
use App\Events\Widget\WidgetCloned;
use Cms\Core\Facades\CMSCache;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Core\Services\Widget\PageWidgetBlockWalker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/**
 * Clones a hub widget into a site-owned copy and moves every stored use on this site to the clone.
 *
 * Stored uses are widget blocks in `posts.content` (every post row, so translated copies stored as
 * sibling rows with their own `language_id` are covered; blocks nested in containers are covered by
 * {@see PageWidgetBlockWalker}) and single widget blocks in `contents.custom` (RealEstate property
 * content). Only the block identity is rewritten (widget_id, template_id, widget_key, theme_key and the
 * ids inside an `inputs` snapshot); `value` is never touched.
 *
 * The clone's generated template files are published inside the clone transaction, before the page
 * rewrite commits: pages never point at a clone theme without a template file, and a failed publish
 * rolls the clone and the page rewrite back. After commit the slug-detail cache of every rewritten
 * page is forgotten, so the public page stops serving the cached hub ids.
 */
class WidgetCloneService
{
    public function __construct(
        private readonly PageWidgetBlockWalker $walker,
        private readonly WidgetAuditRecorder $audit,
        private readonly ?LegacyWidgetArtifactSetPublisher $publisher = null,
        private readonly ?WidgetPreviewImages $previewImages = null,
    ) {
    }

    /**
     * A site clone of the same hub created this recently is edited instead of cloning again, so two
     * admins (or a double submit) saving the same hub widget at once end up with one clone.
     */
    public const REUSE_CLONE_WITHIN_SECONDS = 60;

    /** Appended to the hub name so the admin list tells a site clone from its hub. */
    public const CLONE_NAME_SUFFIX = ' (bản riêng)';

    /** {@see CLONE_NAME_SUFFIX} per language of the per-language names. */
    public const CLONE_NAME_SUFFIXES = ['en' => ' (own copy)', 'vi' => self::CLONE_NAME_SUFFIX];

    public function cloneForSite(Widget $hub, int $userId): WidgetCloneResult
    {
        return DB::transaction(fn (): WidgetCloneResult => $this->cloneLocked($this->lockHub($hub), $userId));
    }

    /**
     * Like {@see cloneForSite()}, but returns a clone of the same hub made within
     * {@see REUSE_CLONE_WITHIN_SECONDS} when one exists. The lookup runs after the hub row lock is
     * taken, so a request that waited for a concurrent clone sees that clone instead of making a second.
     * A reused clone reports `pagesUpdated = 0` (its creator already moved the pages) and maps hub
     * themes to clone themes by theme_key. A blocked clone is skipped, so a new clone is made instead.
     */
    public function cloneOrReuseForSite(Widget $hub, int $userId): WidgetCloneResult
    {
        return DB::transaction(function () use ($hub, $userId): WidgetCloneResult {
            $locked = $this->lockHub($hub);

            // Plain read for the candidate, then lock only that row. A locking read over these
            // unindexed columns would take next-key locks on every scanned row on MySQL. Lock order
            // stays hub -> clone. A blocked clone is never reused (re-checked under the row lock).
            $candidateId = Widget::query()
                ->where('origin', Widget::ORIGIN_SITE)
                ->where('cloned_from_widget_key', $locked->widget_key)
                ->where('cloned_at', '>=', now()->subSeconds(self::REUSE_CLONE_WITHIN_SECONDS))
                ->whereNull('blocked_at')
                ->orderByDesc('id')
                ->value('id');

            /** @var Widget|null $recent */
            $recent = $candidateId === null ? null : Widget::query()
                ->whereKey($candidateId)
                ->whereNull('blocked_at')
                ->lockForUpdate()
                ->first();

            if ($recent === null) {
                return $this->cloneLocked($locked, $userId);
            }

            $cloneThemeIds = $recent->template()->pluck('id', 'theme_key');
            $themeIdMap = [];
            foreach ($locked->template()->orderBy('id')->get(['id', 'theme_key']) as $theme) {
                if (isset($cloneThemeIds[(string) $theme->theme_key])) {
                    $themeIdMap[(int) $theme->id] = (int) $cloneThemeIds[(string) $theme->theme_key];
                }
            }

            return new WidgetCloneResult($recent, $themeIdMap, 0);
        });
    }

    private function lockHub(Widget $hub): Widget
    {
        /** @var Widget $locked */
        $locked = Widget::query()->whereKey($hub->getKey())->lockForUpdate()->firstOrFail();

        if (!$locked->isHub()) {
            throw new LogicException("Only hub widgets can be cloned for a site (widget {$locked->id}).");
        }

        return $locked;
    }

    /** Clone a hub row the caller has locked, inside the caller's transaction. */
    private function cloneLocked(Widget $locked, int $userId): WidgetCloneResult
    {
        $clone = $locked->replicate();
        $clone->forceFill([
            'widget_key' => $this->nextCloneKey((string) $locked->widget_key),
            'name' => self::cloneName((string) $locked->name),
            'name_translations' => self::cloneNameTranslations($locked->name_translations),
            'origin' => Widget::ORIGIN_SITE,
            'publisher' => null,
            'cloned_from_widget_key' => $locked->widget_key,
            'cloned_at' => now(),
        ])->save();

        $themes = $locked->template()->orderBy('id')->get();
        $themeIdMap = [];
        $cloneThemesByKey = [];
        foreach ($themes as $theme) {
            $copy = $theme->replicate();
            $copy->widget_id = $clone->id;
            // The clone owns a copy of the hub preview (never the hub's file). Every clone design is stale until the
            // admin captures it anew, also one whose hub design had no picture.
            if (!empty($theme->preview_image)) {
                $copy->preview_image = $this->previews()->copyFor(
                    (string) $theme->preview_image,
                    (string) $clone->widget_key,
                    (string) $theme->theme_key,
                );
            }
            $copy->preview_stale = true;
            $copy->save();
            $themeIdMap[(int) $theme->id] = (int) $copy->id;
            $cloneThemesByKey[(string) $theme->theme_key] = $copy;
        }

        $inputIdMap = [];
        foreach ($locked->inputs()->orderBy('id')->get() as $input) {
            $copy = $input->replicate();
            $copy->widget_id = $clone->id;
            $copy->save();
            $inputIdMap[(int) $input->id] = (int) $copy->id;
        }

        // Same connection as this transaction, so the publisher reads the uncommitted clone themes.
        // A failure throws and rolls everything back; pages keep pointing at the hub.
        ($this->publisher ?? app(LegacyWidgetArtifactSetPublisher::class))
            ->publish($this->projectNames($cloneThemesByKey));

        $rewrite = $this->blockRewriter($locked, $clone, $themes, $cloneThemesByKey, $inputIdMap);
        $rewrittenPostIds = [];
        $pagesUpdated = $this->rewriteJsonColumn('posts', 'content', $rewrite, false, $rewrittenPostIds);
        $this->rewriteJsonColumn('contents', 'custom', $rewrite, true);

        $slugs = $this->postSlugs($rewrittenPostIds);
        DB::afterCommit(fn () => $this->forgetSlugDetails($slugs));

        $this->audit->record(WidgetAuditRecorder::ACTION_CLONED, $clone, $userId, [
            'hub_key' => (string) $locked->widget_key,
            'clone_key' => (string) $clone->widget_key,
            'pages_updated' => $pagesUpdated,
        ]);

        event(new WidgetCloned((string) $locked->widget_key, (int) $clone->id, $themeIdMap, $userId));

        return new WidgetCloneResult($clone->fresh(), $themeIdMap, $pagesUpdated);
    }

    private function previews(): WidgetPreviewImages
    {
        return $this->previewImages ?? app(WidgetPreviewImages::class);
    }

    /** Hub name with {@see CLONE_NAME_SUFFIX}, added once. */
    public static function cloneName(string $hubName): string
    {
        return str_ends_with($hubName, self::CLONE_NAME_SUFFIX) ? $hubName : $hubName . self::CLONE_NAME_SUFFIX;
    }

    /**
     * Per-language hub names with the suffix of each language, added once; null when the hub has none.
     *
     * @return array<string, string>|null
     */
    public static function cloneNameTranslations(mixed $translations): ?array
    {
        $names = WidgetNameTranslations::normalize($translations);
        if ($names === null) {
            return null;
        }
        foreach ($names as $locale => $name) {
            $suffix = self::CLONE_NAME_SUFFIXES[$locale];
            $names[$locale] = str_ends_with($name, $suffix) ? $name : $name . $suffix;
        }

        return $names;
    }

    /** First free `site.<hub key>-<n>`, n from 1. Callers hold the hub row lock, which serializes clones. */
    public function nextCloneKey(string $hubKey): string
    {
        $prefix = "site.{$hubKey}-";
        // LIKE wildcards in the key ('_') only widen the candidate set; the check below decides.
        // Compared case-insensitively because MySQL's unique index on widget_key is.
        $taken = Widget::query()
            ->where('widget_key', 'like', $prefix . '%')
            ->pluck('widget_key')
            ->map(fn (string $key): string => mb_strtolower($key))
            ->flip();

        $n = 1;
        while ($taken->has(mb_strtolower($prefix . $n))) {
            $n++;
        }

        return $prefix . $n;
    }

    /**
     * Visitor that moves a block referencing the hub to the clone. Matching mirrors
     * LegacyWidgetReferenceResolver: stable keys win when both are present, otherwise numeric ids.
     *
     * @param Collection<int, WidgetTheme> $hubThemes
     * @param array<string, WidgetTheme> $cloneThemesByKey
     * @param array<int, int> $inputIdMap
     * @return callable(array<string, mixed>, list<array-key>): array<string, mixed>
     */
    private function blockRewriter(
        Widget $hub,
        Widget $clone,
        Collection $hubThemes,
        array $cloneThemesByKey,
        array $inputIdMap,
    ): callable {
        return function (array $block) use ($hub, $clone, $hubThemes, $cloneThemesByKey, $inputIdMap): array {
            $widgetKey = (string) ($block['widget_key'] ?? '');
            $themeKey = (string) ($block['theme_key'] ?? '');

            if ($widgetKey !== '' && $themeKey !== '') {
                $hubTheme = $widgetKey === $hub->widget_key ? $hubThemes->firstWhere('theme_key', $themeKey) : null;
            } else {
                $hubTheme = (int) ($block['widget_id'] ?? 0) === (int) $hub->id
                    ? $hubThemes->firstWhere('id', (int) ($block['template_id'] ?? 0))
                    : null;
            }

            // Not a use of this hub, or a broken reference to it: leave the block exactly as stored.
            if ($hubTheme === null || !isset($cloneThemesByKey[(string) $hubTheme->theme_key])) {
                return $block;
            }

            $block['widget_id'] = (int) $clone->id;
            $block['template_id'] = (int) $cloneThemesByKey[(string) $hubTheme->theme_key]->id;
            $block['widget_key'] = (string) $clone->widget_key;
            $block['theme_key'] = (string) $hubTheme->theme_key;

            // The editor stores a snapshot of the widget input rows (with their ids) next to the value.
            if (isset($block['inputs']) && is_array($block['inputs'])) {
                foreach ($block['inputs'] as $i => $input) {
                    if (is_array($input) && isset($input['id'], $inputIdMap[(int) $input['id']])) {
                        $block['inputs'][$i]['id'] = $inputIdMap[(int) $input['id']];
                        if (array_key_exists('widget_id', $input)) {
                            $block['inputs'][$i]['widget_id'] = (int) $clone->id;
                        }
                    }
                }
            }

            return $block;
        };
    }

    /**
     * Point stored blocks that reference `$fromKey` by widget_key + theme_key at `$toKey` (widget hub switch-over,
     * where a hub widget becomes a site copy in place). Only `widget_key` changes; ids, theme_key and values stay.
     * Same scan, locked re-read and slug-detail cache forget as a clone. Call inside a transaction when writing.
     *
     * @return array{posts: list<int|string>, contents: list<int|string>} rows changed, or that would change when
     *         `$write` is false (nothing is written then)
     */
    public function rewriteKeyReferences(string $fromKey, string $toKey, bool $write = true): array
    {
        $rewrite = static function (array $block) use ($fromKey, $toKey): array {
            if ((string) ($block['widget_key'] ?? '') === $fromKey && (string) ($block['theme_key'] ?? '') !== '') {
                $block['widget_key'] = $toKey;
            }

            return $block;
        };

        $postIds = [];
        $contentIds = [];
        $this->rewriteJsonColumn('posts', 'content', $rewrite, false, $postIds, $write);
        $this->rewriteJsonColumn('contents', 'custom', $rewrite, true, $contentIds, $write);

        if ($write) {
            $slugs = $this->postSlugs($postIds);
            DB::afterCommit(fn () => $this->forgetSlugDetails($slugs));
        }

        return ['posts' => $postIds, 'contents' => $contentIds];
    }

    /**
     * Rewrite widget blocks stored in a JSON column and return how many rows changed.
     * `$single` means the column holds one block object instead of a block list. With `$write` false only the
     * unlocked scan runs: the rows that would change are returned in `$updatedIds` and nothing is written.
     */
    private function rewriteJsonColumn(string $table, string $column, callable $rewrite, bool $single, array &$updatedIds = [], bool $write = true): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        // Unlocked scan: only finds the rows that need a change.
        $candidateIds = [];
        DB::table($table)->select(['id', $column])->whereNotNull($column)->orderBy('id')
            ->chunkById(200, function (Collection $rows) use ($column, $rewrite, $single, &$candidateIds): void {
                foreach ($rows as $row) {
                    if ($this->rewriteValue($row->{$column}, $rewrite, $single) !== null) {
                        $candidateIds[] = $row->id;
                    }
                }
            });

        if (!$write) {
            array_push($updatedIds, ...$candidateIds);

            return count($candidateIds);
        }

        $updated = 0;
        foreach ($candidateIds as $id) {
            // Re-read with a row lock before writing. The scan above may have seen an older version
            // (another editor saving meanwhile, or a REPEATABLE READ snapshot fixed early in this
            // transaction); a locking read returns the latest committed content, so a concurrent save
            // is rewritten instead of being overwritten with stale content.
            $fresh = $this->readRowForUpdate($table, $column, $id);
            $json = $fresh === null ? null : $this->rewriteValue($fresh, $rewrite, $single);
            if ($json === null) {
                continue;
            }

            DB::table($table)->where('id', $id)->update([$column => $json]);
            $updatedIds[] = $id;
            $updated++;
        }

        return $updated;
    }

    /**
     * Project names the clone themes publish to, with the generator's fan-out: a scalar or missing
     * project_name means Default, an explicit list (even empty) is used as is.
     *
     * @param array<string, WidgetTheme> $themes
     * @return array<int, string>
     */
    private function projectNames(array $themes): array
    {
        $names = [];
        foreach ($themes as $theme) {
            $projects = $theme->project_name;
            foreach (is_array($projects) ? $projects : [!empty($projects) ? $projects : 'Default'] as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Slugs (with their language) of the given posts. Read from the slugs table directly: Core does not
     * depend on the Content module that owns the Post model.
     *
     * @param list<int|string> $postIds
     * @return list<array{slug: string, language_id: int|string|null}>
     */
    private function postSlugs(array $postIds): array
    {
        if ($postIds === [] || !Schema::hasTable('slugs')) {
            return [];
        }

        return DB::table('slugs')
            ->whereIn('target_id', $postIds)
            ->get(['slug', 'target_type', 'language_id'])
            ->filter(fn ($row): bool => (string) $row->slug !== '' && str_ends_with((string) $row->target_type, '\\Post'))
            ->map(fn ($row): array => ['slug' => (string) $row->slug, 'language_id' => $row->language_id])
            ->values()
            ->all();
    }

    /**
     * Forget the unversioned slug-detail cache (CacheKeyHelper::slugDetail, 1-2 h) of rewritten pages,
     * under the slug's language and the current one, like the Content post cache invalidation does.
     *
     * @param list<array{slug: string, language_id: int|string|null}> $slugs
     */
    private function forgetSlugDetails(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $languageIds = array_unique(array_filter(
                [$slug['language_id'], CacheKeyHelper::getLangId()],
                static fn ($id): bool => $id !== null && $id !== ''
            ));
            foreach ($languageIds as $languageId) {
                try {
                    CMSCache::forget(CacheKeyHelper::slugDetail($slug['slug'], $languageId));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /** Latest committed column value of one row, locked until the clone transaction ends. */
    protected function readRowForUpdate(string $table, string $column, int|string $id): ?string
    {
        $value = DB::table($table)->where('id', $id)->lockForUpdate()->value($column);

        return $value === null ? null : (string) $value;
    }

    /** Encoded rewritten JSON, or null when the stored value has no use of the hub to rewrite. */
    private function rewriteValue(mixed $raw, callable $rewrite, bool $single): ?string
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $content = $single ? [$decoded] : $decoded;
        $rewritten = $this->walker->walk($content, $rewrite);
        if ($rewritten === $content) {
            return null;
        }

        return json_encode($single ? $rewritten[0] : $rewritten, JSON_THROW_ON_ERROR);
    }
}
