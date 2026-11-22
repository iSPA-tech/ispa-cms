<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget;

use Closure;
use Throwable;
use RuntimeException;
use Illuminate\Support\Str;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use App\Services\WidgetTemplatePathResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Publishes the legacy Widget/Theme Blade artifact set through one global
 * active-generation topology (docs/architecture/adr-widget-artifact-store.md).
 *
 * Storage layout under storage_path('app/widget_generations'):
 * - ACTIVE            plain-text pointer file containing the current generation id
 * - HISTORY           JSON array of previously-active generation ids (most recent first)
 * - .staging/{id}/    transient work directory for an in-flight run; never referenced
 *                     by a manifest, always safe to delete (abandoned-staging retry)
 * - {id}/manifest.json  the generation's own immutable project map
 * - {id}/{project-slug}/widget_{wid}_theme_{tid}.blade.php  physical rendered files,
 *   present only for the generation that actually owns the bytes for that project
 *   (a project entry may reference an OLDER generation id when its set was carried
 *   forward unchanged, per ADR decision 3 — no redundant copies are made)
 *
 * Every publish() call runs under one global lock (ADR decision 1), fully rebuilds
 * every affected project's complete set (decision 2), carries forward references
 * for every unaffected project (decision 3), verifies file count/bytes/checksum/
 * readability plus the project-set hash before publication (decision 4), and only
 * then atomically renames the ACTIVE pointer (decision 5). Any failure leaves the
 * prior pointer/generation graph untouched (decision 6). The lock-protected
 * mark/sweep of decision 8 runs automatically after every successful pointer
 * switch (still under the same lock) and is also exposed as cleanup() for
 * operator-driven runs.
 *
 * @package Cms\Core\Services\Widget
 */
final class LegacyWidgetArtifactSetPublisher
{
    private const LOCK_NAME = 'widget-artifact-generation-lock';
    private const DIRECTORY_MODE = 0755;

    /**
     * @param Closure|null $readinessBarrier callable(string $generationId, array $projectMap): bool.
     *        Defaults to an always-pass closure, which trivially satisfies the
     *        readiness barrier on the proven single-node topology (ADR decision 9).
     * @param int $lockWaitSeconds How long to block waiting for the global lock.
     * @param int $lockTtlSeconds Lock lease length; must exceed one COMPLETE
     *        publish run. Sizing rationale: a run covers the full-set render
     *        of every affected project (a large site can carry hundreds of
     *        Widget/Theme files per project), per-file sha256 hashing,
     *        carried-reference re-verification, manifest write/read-back, the
     *        pointer switch, and the post-switch mark/sweep — all inside one
     *        lease. 120s gives comfortable headroom over the worst case
     *        observed locally; the first supervised production regeneration
     *        (preflight gap G8) should be measured before tuning further.
     *        A lease expiring mid-run risks a second generator entering while
     *        the first still writes staging, which the topology forbids.
     * @param int $retainedGenerations How many previously-active generations stay reachable.
     */
    public function __construct(
        private readonly ?Closure $readinessBarrier = null,
        private readonly int $lockWaitSeconds = 10,
        private readonly int $lockTtlSeconds = 120,
        private readonly int $retainedGenerations = 2,
    ) {
    }

    // -----------------------------------------------------------------
    // Static path helpers — shared with WidgetTemplatePathResolver so the
    // storage layout is defined in exactly one place.
    // -----------------------------------------------------------------

    public static function rootPath(): string
    {
        return storage_path('app/widget_generations');
    }

    public static function stagingRoot(): string
    {
        return self::rootPath() . '/.staging';
    }

    public static function activePointerPath(): string
    {
        return self::rootPath() . '/ACTIVE';
    }

    public static function historyPath(): string
    {
        return self::rootPath() . '/HISTORY';
    }

    public static function generationPath(string $generationId): string
    {
        return self::rootPath() . '/' . $generationId;
    }

    public static function generationManifestPath(string $generationId): string
    {
        return self::generationPath($generationId) . '/manifest.json';
    }

    /**
     * Read-only: resolve the currently active generation manifest, if any.
     * Safe to call without the lock — the pointer only ever points at a
     * fully-written, verified generation (atomic rename per decision 5).
     */
    public static function readActiveManifest(): ?LegacyWidgetArtifactGenerationManifest
    {
        $pointerPath = self::activePointerPath();
        if (!is_file($pointerPath) || !is_readable($pointerPath)) {
            return null;
        }

        $generationId = trim((string) file_get_contents($pointerPath));
        if ($generationId === '') {
            return null;
        }

        $manifestPath = self::generationManifestPath($generationId);
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return null;
        }

        try {
            return LegacyWidgetArtifactGenerationManifest::fromJson((string) file_get_contents($manifestPath));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the on-disk path for one project's file within a manifest,
     * following the entry's own generation_id (which may differ from the
     * manifest's generation when the project set was carried forward).
     */
    public static function resolveProjectFilePath(
        LegacyWidgetArtifactGenerationManifest $manifest,
        string $projectName,
        string $filename
    ): ?string {
        $entry = $manifest->projectEntry($projectName);
        if ($entry === null || !isset($entry['files'][$filename])) {
            return null;
        }

        $owningGenerationId = (string) ($entry['generation_id'] ?? '');
        $slug = (string) ($entry['slug'] ?? '');
        if ($owningGenerationId === '' || $slug === '') {
            return null;
        }

        $path = self::generationPath($owningGenerationId) . '/' . $slug . '/' . $filename;

        return is_file($path) ? $path : null;
    }

    // -----------------------------------------------------------------
    // Mutating operations
    // -----------------------------------------------------------------

    /**
     * Rebuild every project named in $affectedProjects (their COMPLETE current
     * Widget/Theme set), carry forward every other known project unchanged,
     * verify the whole graph, and atomically switch the active pointer.
     *
     * @param array<int, string> $affectedProjects
     */
    public function publish(array $affectedProjects): LegacyWidgetArtifactGenerationManifest
    {
        $lock = Cache::lock(self::LOCK_NAME, $this->lockTtlSeconds);

        try {
            $lock->block($this->lockWaitSeconds);
        } catch (LockTimeoutException $e) {
            throw new RuntimeException(
                'Widget artifact publication lock timed out; another generation run is in progress.',
                0,
                $e
            );
        }

        try {
            return $this->publishUnderLock($affectedProjects);
        } finally {
            $lock->release();
        }
    }

    /**
     * Lock-protected mark/sweep cleanup (ADR decision 8). Deletes only
     * generation directories unreachable from the active pointer plus its
     * retained previous generations, following carry-forward references
     * transitively. Never infers reachability from database rows.
     *
     * @return array<int, string> Removed generation ids.
     */
    public function cleanup(): array
    {
        $lock = Cache::lock(self::LOCK_NAME, $this->lockTtlSeconds);

        try {
            $lock->block($this->lockWaitSeconds);
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('Widget artifact cleanup lock timed out.', 0, $e);
        }

        try {
            return $this->cleanupUnderLock();
        } finally {
            $lock->release();
        }
    }

    /**
     * Group every current WidgetTheme (with its Widget) by expanded project
     * name, mirroring the exact legacy fan-out semantics: a scalar or missing
     * project_name normalizes to ['Default']; an explicit array (including an
     * empty one) is used as-is.
     *
     * @return array<string, array<int, WidgetTheme>>
     */
    public function buildProjectThemeMap(): array
    {
        $map = [];

        foreach (WidgetTheme::with('widget')->get() as $theme) {
            foreach ($this->expandProjectNames($theme->project_name) as $name) {
                $map[$name][] = $theme;
            }
        }

        return $map;
    }

    /**
     * Every project currently known: from the database (current Widget/Theme
     * applicability) plus from the active generation manifest (so a project
     * that just dropped to zero themes is still rebuilt to an empty set
     * instead of carrying forward stale files). Used for full resync
     * operations (the `cms:generate-widget-template` command and the
     * operator retry command/action).
     *
     * @return array<int, string>
     */
    public function allKnownProjectNames(): array
    {
        $current = array_keys($this->buildProjectThemeMap());
        $previous = self::readActiveManifest()?->projectNames() ?? [];

        return array_values(array_unique([...$current, ...$previous]));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function publishUnderLock(array $affectedProjects): LegacyWidgetArtifactGenerationManifest
    {
        File::ensureDirectoryExists(self::rootPath(), self::DIRECTORY_MODE);

        // Abandoned staging retry: nothing durable ever references a .staging
        // path, so any leftover directory here is a crashed prior run.
        $this->sweepStagingRoot();

        $previous = self::readActiveManifest();
        $previousProjects = $previous?->projects ?? [];

        $affected = array_values(array_unique(array_filter(
            $affectedProjects,
            static fn ($name) => is_string($name) && $name !== ''
        )));
        $universe = array_values(array_unique([...array_keys($previousProjects), ...$affected]));

        // Fail fast on slug collisions: two DISTINCT project names mapping to
        // the same directory slug would silently write into (and hash over)
        // each other's set. Refusing here turns the mutation's sync_status
        // into a clean `pending` with no staging corruption and no pointer
        // movement.
        $slugOwners = [];
        foreach ($universe as $projectName) {
            $slug = $this->projectSlug($projectName);
            if (isset($slugOwners[$slug]) && $slugOwners[$slug] !== $projectName) {
                throw new RuntimeException(sprintf(
                    "Project slug collision: '%s' and '%s' both map to slug '%s'; refusing to publish.",
                    $slugOwners[$slug],
                    $projectName,
                    $slug
                ));
            }
            $slugOwners[$slug] = $projectName;
        }

        $generationId = $this->newGenerationId();
        $stagingDir = self::stagingRoot() . '/' . $generationId;

        try {
            File::ensureDirectoryExists($stagingDir, self::DIRECTORY_MODE);

            $projectThemeMap = null;
            $newProjects = [];

            foreach ($universe as $projectName) {
                if (in_array($projectName, $affected, true)) {
                    $projectThemeMap ??= $this->buildProjectThemeMap();
                    $themes = $projectThemeMap[$projectName] ?? [];

                    $newProjects[$projectName] = $this->renderAndVerifyProject(
                        $stagingDir,
                        $generationId,
                        $projectName,
                        $themes,
                        $previousProjects[$projectName] ?? null
                    );
                } else {
                    $carried = $previousProjects[$projectName] ?? null;
                    if ($carried === null || !$this->carriedReferenceIsReadable($carried)) {
                        throw new RuntimeException(
                            "Carried-forward project '{$projectName}' is missing or unreadable; refusing to publish."
                        );
                    }
                    $newProjects[$projectName] = $carried;
                }
            }

            ksort($newProjects);
            $manifest = new LegacyWidgetArtifactGenerationManifest($generationId, gmdate('c'), $newProjects);

            // No-duplicate-retry: identical project content as the currently
            // active generation performs no mutation at all.
            if ($previous !== null && $previous->canonicalProjectsJson() === $manifest->canonicalProjectsJson()) {
                File::deleteDirectory($stagingDir);
                return $previous;
            }

            $barrier = $this->readinessBarrier ?? static fn (): bool => true;
            if (!$barrier($generationId, $newProjects)) {
                throw new RuntimeException(
                    'Readiness barrier rejected generation before pointer switch; prior generation retained.'
                );
            }

            // Checked write + read-back verification of the manifest BEFORE
            // any pointer movement: a short/failed write or a manifest that
            // does not parse back to identical content aborts the run with
            // staging-only cleanup, prior generation retained.
            $manifestPath = $stagingDir . '/manifest.json';
            $this->writeFileChecked($manifestPath, $manifest->toCanonicalJson());

            $reRead = LegacyWidgetArtifactGenerationManifest::fromJson((string) file_get_contents($manifestPath));
            if ($reRead->generationId !== $manifest->generationId
                || $reRead->canonicalProjectsJson() !== $manifest->canonicalProjectsJson()
            ) {
                throw new RuntimeException('Generation manifest failed read-back verification.');
            }

            $finalDir = self::generationPath($generationId);
            if (!@rename($stagingDir, $finalDir)) {
                throw new RuntimeException("Unable to finalize generation directory '{$finalDir}'.");
            }

            $this->switchActivePointer($generationId, $previous?->generationId);

            WidgetTemplatePathResolver::clearCache();

            // Mark/sweep runs after every successful switch, still under the
            // same global lock (ADR decision 8): generations now unreachable
            // from the new active pointer plus retained-previous history are
            // swept immediately; anything still referenced (carry-forward)
            // survives. A sweep failure must NOT turn the already-committed
            // publish (pointer switched, generation live) into a failure —
            // it is reported and left for the next lock-holding run.
            try {
                $this->cleanupUnderLock();
            } catch (Throwable $sweepError) {
                report($sweepError);
            }

            return $manifest;
        } catch (Throwable $e) {
            if (is_dir($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }
            throw $e;
        }
    }

    /**
     * Render one project's complete theme set into staging, verify file
     * count/bytes/checksum, and dedupe against an identical previous entry
     * so unchanged content never grows the on-disk generation history.
     *
     * @param array<int, WidgetTheme> $themes
     */
    private function renderAndVerifyProject(
        string $stagingDir,
        string $generationId,
        string $projectName,
        array $themes,
        ?array $previousEntry
    ): array {
        $slug = $this->projectSlug($projectName);
        $dir = $stagingDir . '/' . $slug;
        File::ensureDirectoryExists($dir, self::DIRECTORY_MODE);

        $files = [];
        foreach ($themes as $theme) {
            $filename = "widget_{$theme->widget_id}_theme_{$theme->id}.blade.php";
            $content = $this->renderThemeContent($theme);
            $path = $dir . '/' . $filename;

            $written = file_put_contents($path, $content);
            if ($written === false || $written !== strlen($content)) {
                throw new RuntimeException(
                    "Short write producing '{$path}' for project '{$projectName}' "
                    . "(wrote " . var_export($written, true) . " of " . strlen($content) . " bytes)."
                );
            }

            // Umask-independent least privilege: file_put_contents creates
            // 0666 & ~umask, which a zero umask leaves world-writable.
            chmod($path, 0644);

            clearstatcache(true, $path);
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException("Generated file '{$path}' is not readable after write.");
            }

            $actualBytes = filesize($path);
            $actualHash = hash_file('sha256', $path);
            $expectedHash = hash('sha256', $content);
            if ($actualBytes !== strlen($content) || $actualHash !== $expectedHash) {
                throw new RuntimeException("Checksum mismatch verifying '{$path}'.");
            }

            $files[$filename] = ['size' => $actualBytes, 'sha256' => $actualHash];
        }

        if (count($files) !== count($themes)) {
            throw new RuntimeException("File count mismatch for project '{$projectName}'.");
        }

        ksort($files);
        $setHash = hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));

        if ($previousEntry !== null
            && ($previousEntry['set_hash'] ?? null) === $setHash
            && $this->carriedReferenceIsReadable($previousEntry)
        ) {
            // Identical content already published under a retained generation:
            // discard the redundant fresh copy and carry the old reference.
            File::deleteDirectory($dir);

            return $previousEntry;
        }

        return [
            'generation_id' => $generationId,
            'slug' => $slug,
            'set_hash' => $setHash,
            'files' => $files,
        ];
    }

    private function carriedReferenceIsReadable(array $entry): bool
    {
        $owningGenerationId = (string) ($entry['generation_id'] ?? '');
        $slug = (string) ($entry['slug'] ?? '');
        if ($owningGenerationId === '' || $slug === '') {
            return false;
        }

        $dir = self::generationPath($owningGenerationId) . '/' . $slug;
        foreach (($entry['files'] ?? []) as $filename => $meta) {
            $path = $dir . '/' . $filename;
            if (!is_file($path) || !is_readable($path)) {
                return false;
            }
            if (isset($meta['sha256']) && hash_file('sha256', $path) !== $meta['sha256']) {
                return false;
            }
        }

        return true;
    }

    private function cleanupUnderLock(): array
    {
        $this->sweepStagingRoot();

        $root = self::rootPath();
        if (!is_dir($root)) {
            return [];
        }

        $reachable = $this->reachableGenerationIds();
        $removed = [];

        foreach (File::directories($root) as $dir) {
            $name = basename($dir);
            if ($name === '.staging' || in_array($name, $reachable, true)) {
                continue;
            }
            File::deleteDirectory($dir);
            $removed[] = $name;
        }

        return $removed;
    }

    /**
     * @return array<int, string>
     */
    private function reachableGenerationIds(): array
    {
        $queue = [];

        $active = is_file(self::activePointerPath())
            ? trim((string) file_get_contents(self::activePointerPath()))
            : '';
        if ($active !== '') {
            $queue[] = $active;
        }

        foreach ($this->readHistory() as $id) {
            if ($id !== '') {
                $queue[] = $id;
            }
        }

        $reachable = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === '' || in_array($id, $reachable, true)) {
                continue;
            }
            $reachable[] = $id;

            $manifestPath = self::generationManifestPath($id);
            if (!is_file($manifestPath)) {
                continue;
            }

            try {
                $manifest = LegacyWidgetArtifactGenerationManifest::fromJson(
                    (string) file_get_contents($manifestPath)
                );
            } catch (Throwable) {
                continue;
            }

            foreach ($manifest->projects as $entry) {
                $refId = (string) ($entry['generation_id'] ?? '');
                if ($refId !== '' && !in_array($refId, $reachable, true)) {
                    $queue[] = $refId;
                }
            }
        }

        return array_values(array_unique($reachable));
    }

    /**
     * @return array<int, string>
     */
    private function readHistory(): array
    {
        $path = self::historyPath();
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? array_values(array_map('strval', $data)) : [];
    }

    /**
     * Checked write with least-privilege permissions: the byte count must
     * match exactly (a short write is a hard failure, never silent), and the
     * file is chmod'd 0644 so a permissive umask can never leave publication
     * metadata world-writable.
     */
    private function writeFileChecked(string $path, string $contents): void
    {
        $written = File::put($path, $contents);

        if ($written === false || $written !== strlen($contents)) {
            throw new RuntimeException(sprintf(
                "Short write producing '%s' (wrote %s of %d bytes).",
                $path,
                $written === false ? 'false' : (string) $written,
                strlen($contents)
            ));
        }

        chmod($path, 0644);
    }

    private function writeHistoryAtomically(array $ids): void
    {
        $path = self::historyPath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        try {
            $this->writeFileChecked($tmp, json_encode(array_values($ids), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to persist widget artifact generation history.');
        }
    }

    private function switchActivePointer(string $newGenerationId, ?string $previousGenerationId): void
    {
        if ($previousGenerationId !== null && $previousGenerationId !== '') {
            $history = array_values(array_unique([$previousGenerationId, ...$this->readHistory()]));
            $history = array_slice($history, 0, $this->retainedGenerations);
            $this->writeHistoryAtomically($history);
        }

        $pointerPath = self::activePointerPath();
        $tmp = $pointerPath . '.tmp.' . bin2hex(random_bytes(4));

        try {
            $this->writeFileChecked($tmp, $newGenerationId);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        if (!@rename($tmp, $pointerPath)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to switch the active widget artifact generation pointer.');
        }
    }

    private function sweepStagingRoot(): void
    {
        $stagingRoot = self::stagingRoot();
        if (!is_dir($stagingRoot)) {
            return;
        }

        foreach (File::directories($stagingRoot) as $dir) {
            File::deleteDirectory($dir);
        }
    }

    private function newGenerationId(): string
    {
        return gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
    }

    private function projectSlug(string $projectName): string
    {
        $slug = (string) Str::slug($projectName);

        return $slug !== '' ? $slug : 'default';
    }

    /**
     * @return array<int, string>
     */
    private function expandProjectNames(mixed $projectNames): array
    {
        if (!is_array($projectNames)) {
            return !empty($projectNames) ? [$projectNames] : ['Default'];
        }

        return $projectNames;
    }

    /**
     * Render one Theme's final generated Blade bytes -- the SAME bytes this
     * publisher writes into a project's generated artifact set.
     *
     * Chunk 2 Task 15a disclosed deviation (not in that task's literal
     * allowed_paths; a narrow, additive visibility widening of an existing
     * method, zero behavior change): widened from `private` to `public`.
     * The Task 10 closure record deliberately built no byte-generator
     * adapter seam, identified this method as the real legacy byte
     * generator (outside the module's boundary), and left the seam open
     * for Task 15a, whose module-owned pending-Manifest materializer is
     * the first caller that must source actual generated bytes into the
     * artifact builder. Exposing this one method reuses the rendering
     * algorithm without duplicating it inside the module. Still called
     * identically by {@see self::renderAndVerifyProject()}.
     */
    public function renderThemeContent(WidgetTheme $theme): string
    {
        $jsScript = optional($theme->widget)->js;

        $content = $theme->content . ($jsScript
            ? "\n@push('script')\n" . $jsScript . "\n@endpush\n"
            : '');

        $sectionId = str($theme->name)->slug() . '-w' . $theme->widget_id . '-t' . $theme->id;

        return $this->minifyHtml(
            "{{-- Blade template for widget: {$theme->name} --}}\n"
            . (!empty($theme->css) ? "<style>{$theme->css}</style>\n" : '')
            . $this->replaceSectionId($content, $sectionId)
        );
    }

    // Moved verbatim from the legacy GenerateTemplateBladeCommand: the
    // publisher is now the single reusable rendering seam.
    private function minifyHtml(string $html): string
    {
        $html = str_replace("\t", '', $html);
        $html = (string) preg_replace('/[ ]{2,}/', ' ', $html);

        return preg_replace('/^\s+|\s+$/m', '', $html);
    }

    public function replaceSectionId(string $content = '', string $id = ''): string
    {
        if (empty($content)) {
            return '';
        }
        if (empty($id)) {
            $id = str()->random(10);
        }

        if (preg_match('/<section\s+[^>]*id="/i', $content)) {
            return (string) preg_replace('/<section\s+[^>]*id="(.*?)"/i', '<section id="' . $id . '"', $content, 1);
        }

        return (string) preg_replace('/<section(?![^>]*id=)([^>]*)>/i', '<section id="' . $id . '"$1>', $content, 1);
    }
}
