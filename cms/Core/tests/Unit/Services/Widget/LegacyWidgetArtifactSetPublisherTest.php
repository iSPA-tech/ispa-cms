<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Gate 0 Task 2: characterization + contract tests for
 * LegacyWidgetArtifactSetPublisher, the global active-generation publication
 * topology accepted by docs/architecture/adr-widget-artifact-store.md.
 *
 * Covers the ADR's measurable acceptance evidence directly: short write,
 * failure on the second Theme file, abandoned staging retry, the global
 * generation lock, no-duplicate-retry, unaffected-project carry-forward, a
 * Widget spanning multiple projects, readiness-barrier failure before the
 * pointer switch, and reference-safe mark/sweep cleanup.
 *
 * Fault injection uses a namespaced `file_put_contents` override (same
 * technique already used in this test suite, e.g.
 * cms/Core/tests/Unit/Console/SetupCoreModuleCommandTest.php's namespaced
 * fake-command helper) so a short/failed write can be simulated without any
 * change to production code.
 */

namespace Cms\Core\Services\Widget {

    function file_put_contents($filename, $data, $flags = 0, $context = null)
    {
        if (!str_contains((string) $filename, '.blade.php')) {
            return \file_put_contents($filename, $data, $flags, $context);
        }

        $GLOBALS['__wasp_write_count'] = ($GLOBALS['__wasp_write_count'] ?? 0) + 1;
        $callIndex = $GLOBALS['__wasp_write_count'];

        if (($GLOBALS['__wasp_fail_write_at'] ?? null) === $callIndex) {
            return false;
        }

        if (($GLOBALS['__wasp_short_write_at'] ?? null) === $callIndex) {
            $truncated = substr((string) $data, 0, max(0, strlen((string) $data) - 1));

            return \file_put_contents($filename, $truncated);
        }

        return \file_put_contents($filename, $data, $flags, $context);
    }
}

namespace {

    use Cms\Core\Models\Widget;
    use Cms\Core\Models\WidgetTheme;
    use Cms\Core\Tests\TestCase;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\File;
    use App\Services\WidgetTemplatePathResolver;
    use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

    uses(TestCase::class, RefreshDatabase::class);

    beforeEach(function () {
        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-publisher-' . uniqid('', true);
        File::ensureDirectoryExists($this->isolatedStoragePath . '/app');
        $this->app->useStoragePath($this->isolatedStoragePath);

        $GLOBALS['__wasp_write_count'] = 0;
        $GLOBALS['__wasp_fail_write_at'] = null;
        $GLOBALS['__wasp_short_write_at'] = null;
    });

    afterEach(function () {
        $GLOBALS['__wasp_write_count'] = 0;
        $GLOBALS['__wasp_fail_write_at'] = null;
        $GLOBALS['__wasp_short_write_at'] = null;

        if (!empty($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }
    });

    function waspMakeTheme(string $name, array $projectNames, ?string $content = null): WidgetTheme
    {
        $widget = Widget::query()->create(['name' => "Widget for {$name}"]);

        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => $name,
            'content' => $content ?? "<section><h2>{$name}</h2></section>",
            'project_name' => $projectNames,
        ]);
    }

    function waspRootPath(): string
    {
        return LegacyWidgetArtifactSetPublisher::rootPath();
    }

    // -----------------------------------------------------------------
    // Baseline: publish switches the pointer atomically.
    // -----------------------------------------------------------------

    test('publish renders a project set and atomically switches the active pointer', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $manifest = $publisher->publish(['Alpha']);

        expect(file_get_contents(LegacyWidgetArtifactSetPublisher::activePointerPath()))->toBe($manifest->generationId);

        $entry = $manifest->projectEntry('Alpha');
        expect($entry)->not->toBeNull()
            ->and($entry['generation_id'])->toBe($manifest->generationId)
            ->and($entry['files'])->toHaveCount(1);

        $filename = array_key_first($entry['files']);
        $path = LegacyWidgetArtifactSetPublisher::generationPath($manifest->generationId) . '/' . $entry['slug'] . '/' . $filename;

        expect(is_file($path))->toBeTrue()
            ->and(hash_file('sha256', $path))->toBe($entry['files'][$filename]['sha256'])
            ->and(filesize($path))->toBe($entry['files'][$filename]['size']);
    });

    // -----------------------------------------------------------------
    // Short write: verification catches a byte-count mismatch.
    // -----------------------------------------------------------------

    test('a short write is caught by verification and the prior pointer is retained', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $baseline = $publisher->publish(['Alpha']);

        waspMakeTheme('Alpha Theme Two', ['Alpha']);
        $GLOBALS['__wasp_write_count'] = 0;
        $GLOBALS['__wasp_short_write_at'] = 1;

        expect(fn () => $publisher->publish(['Alpha']))->toThrow(RuntimeException::class, 'Short write');

        expect(file_get_contents(LegacyWidgetArtifactSetPublisher::activePointerPath()))->toBe($baseline->generationId);
        expect(File::directories(LegacyWidgetArtifactSetPublisher::stagingRoot()))->toBe([]);
    });

    // -----------------------------------------------------------------
    // Failure on the second Theme file: whole run fails, prior pointer kept.
    // -----------------------------------------------------------------

    test('a failed write on the second theme file fails the whole run and retains the prior generation', function () {
        waspMakeTheme('Alpha Theme One', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $baseline = $publisher->publish(['Alpha']);
        $baselineDir = LegacyWidgetArtifactSetPublisher::generationPath($baseline->generationId);
        $baselineFilesBefore = File::allFiles($baselineDir);

        waspMakeTheme('Alpha Theme Two', ['Alpha']);
        $GLOBALS['__wasp_write_count'] = 0;
        $GLOBALS['__wasp_fail_write_at'] = 2;

        expect(fn () => $publisher->publish(['Alpha']))->toThrow(RuntimeException::class, 'Short write');

        expect(file_get_contents(LegacyWidgetArtifactSetPublisher::activePointerPath()))->toBe($baseline->generationId);
        expect(File::allFiles($baselineDir))->toHaveCount(count($baselineFilesBefore));
        expect(File::directories(LegacyWidgetArtifactSetPublisher::stagingRoot()))->toBe([]);
    });

    // -----------------------------------------------------------------
    // Abandoned staging retry.
    // -----------------------------------------------------------------

    test('abandoned staging from a crashed run is swept and the next publish succeeds', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $baseline = $publisher->publish(['Alpha']);

        // Simulate a crashed prior run: a staging directory that no manifest
        // will ever reference.
        $abandoned = LegacyWidgetArtifactSetPublisher::stagingRoot() . '/abandoned-run-id';
        File::ensureDirectoryExists($abandoned . '/default');
        File::put($abandoned . '/default/widget_1_theme_1.blade.php', '{{-- abandoned --}}');

        waspMakeTheme('Beta Theme', ['Beta']);
        $manifest = $publisher->publish(['Beta']);

        expect(is_dir($abandoned))->toBeFalse();
        expect($manifest->projectEntry('Beta'))->not->toBeNull();
        // Alpha was carried forward, untouched.
        expect($manifest->projectEntry('Alpha')['generation_id'])->toBe($baseline->generationId);
    });

    // -----------------------------------------------------------------
    // Concurrent generator lock.
    // -----------------------------------------------------------------

    test('a concurrent publish attempt is rejected while the global lock is held', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $externalLock = Cache::lock('widget-artifact-generation-lock', 30);
        expect($externalLock->get())->toBeTrue();

        try {
            $publisher = new LegacyWidgetArtifactSetPublisher(lockWaitSeconds: 1);

            expect(fn () => $publisher->publish(['Alpha']))
                ->toThrow(RuntimeException::class, 'lock timed out');
        } finally {
            $externalLock->release();
        }
    });

    // -----------------------------------------------------------------
    // No duplicate retry.
    // -----------------------------------------------------------------

    test('retrying an identical publish is a no-op and creates no duplicate generation', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $first = $publisher->publish(['Alpha']);

        $second = $publisher->publish(['Alpha']);

        expect($second->generationId)->toBe($first->generationId);

        $generationDirs = array_filter(
            File::directories(waspRootPath()),
            static fn ($dir) => basename($dir) !== '.staging'
        );
        expect($generationDirs)->toHaveCount(1);
        expect(is_file(LegacyWidgetArtifactSetPublisher::historyPath()))->toBeFalse();
    });

    // -----------------------------------------------------------------
    // Unaffected-project carry-forward.
    // -----------------------------------------------------------------

    test('publishing one project carries forward another project unchanged', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);
        $betaTheme = waspMakeTheme('Beta Theme', ['Beta']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $first = $publisher->publish(['Alpha', 'Beta']);
        $alphaEntryBefore = $first->projectEntry('Alpha');

        $betaTheme->update(['content' => '<section><h2>Beta changed</h2></section>']);

        $second = $publisher->publish(['Beta']);

        // Alpha's reference is byte-identical to before: same owning
        // generation id, same file fingerprints — a true carry-forward, not
        // a re-render.
        expect($second->projectEntry('Alpha'))->toBe($alphaEntryBefore);
        // Beta was actually rebuilt under the new generation.
        expect($second->projectEntry('Beta')['generation_id'])->toBe($second->generationId)
            ->and($second->projectEntry('Beta')['generation_id'])->not->toBe($first->generationId);

        $filename = array_key_first($alphaEntryBefore['files']);
        $path = LegacyWidgetArtifactSetPublisher::resolveProjectFilePath($second, 'Alpha', $filename);
        expect($path)->not->toBeNull()->and(is_file($path))->toBeTrue();
    });

    // -----------------------------------------------------------------
    // A Widget spanning multiple projects.
    // -----------------------------------------------------------------

    test('a Widget applying to multiple projects rebuilds all of its project sets in one operation', function () {
        waspMakeTheme('Shared Theme', ['Alpha', 'Beta']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $manifest = $publisher->publish(['Alpha', 'Beta']);

        $alpha = $manifest->projectEntry('Alpha');
        $beta = $manifest->projectEntry('Beta');

        expect($alpha)->not->toBeNull()->and($beta)->not->toBeNull()
            ->and($alpha['generation_id'])->toBe($manifest->generationId)
            ->and($beta['generation_id'])->toBe($manifest->generationId);
    });

    // -----------------------------------------------------------------
    // Readiness barrier failure before switch.
    // -----------------------------------------------------------------

    test('a readiness barrier failure prevents the pointer switch and retains the prior generation', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $baseline = $publisher->publish(['Alpha']);

        waspMakeTheme('Alpha Theme Two', ['Alpha']);

        $blockedPublisher = new LegacyWidgetArtifactSetPublisher(readinessBarrier: fn () => false);

        expect(fn () => $blockedPublisher->publish(['Alpha']))
            ->toThrow(RuntimeException::class, 'Readiness barrier');

        expect(file_get_contents(LegacyWidgetArtifactSetPublisher::activePointerPath()))->toBe($baseline->generationId);
        expect(File::directories(LegacyWidgetArtifactSetPublisher::stagingRoot()))->toBe([]);
    });

    // -----------------------------------------------------------------
    // Reference-safe mark/sweep cleanup.
    // -----------------------------------------------------------------

    test('a successful publish auto-sweeps unreachable generations while retained and referenced ones survive', function () {
        $alphaTheme = waspMakeTheme('Alpha Theme', ['Alpha']);
        waspMakeTheme('Beta Theme', ['Beta']); // Beta never changes again.

        $publisher = new LegacyWidgetArtifactSetPublisher(retainedGenerations: 1);

        // gen1: both projects fresh. Beta's bytes will live here forever —
        // every later generation carries Beta forward by reference.
        $gen1 = $publisher->publish(['Alpha', 'Beta']);

        $alphaTheme->update(['content' => '<section><h2>Alpha v2</h2></section>']);
        $gen2 = $publisher->publish(['Alpha']); // Beta carried from gen1.

        $alphaTheme->update(['content' => '<section><h2>Alpha v3</h2></section>']);
        $gen3 = $publisher->publish(['Alpha']); // Beta carried (via gen2) from gen1.

        $alphaTheme->update(['content' => '<section><h2>Alpha v4</h2></section>']);
        $gen4 = $publisher->publish(['Alpha']); // Beta carried (via gen3) from gen1; pushes gen2 out of history.

        // Mark/sweep ran automatically at the end of gen4's successful
        // publish (under the same global lock): gen2 is superseded (its own
        // Alpha bytes are replaced by gen3/gen4, its carried Beta reference
        // resolved to gen1) and outside the one-retained-previous window
        // (HISTORY = [gen3]) — unreachable and already gone, with NO manual
        // cleanup() call.
        expect(is_dir(LegacyWidgetArtifactSetPublisher::generationPath($gen2->generationId)))->toBeFalse();

        // gen1 is reference-protected: Beta's actual bytes still live only
        // there, and gen4's manifest still names gen1 for Beta — the sweep
        // must NOT delete a generation that is still referenced, even
        // though it long ago fell out of the naive retention window.
        expect(is_dir(LegacyWidgetArtifactSetPublisher::generationPath($gen1->generationId)))->toBeTrue();
        // Active plus the one retained-previous generation both survive.
        expect(is_dir(LegacyWidgetArtifactSetPublisher::generationPath($gen4->generationId)))->toBeTrue();
        expect(is_dir(LegacyWidgetArtifactSetPublisher::generationPath($gen3->generationId)))->toBeTrue();

        // An explicit operator cleanup right after finds nothing further.
        expect($publisher->cleanup())->toBe([]);

        // Beta still resolves correctly through the still-active manifest.
        $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
        $betaEntry = $manifest->projectEntry('Beta');
        $filename = array_key_first($betaEntry['files']);
        $path = LegacyWidgetArtifactSetPublisher::resolveProjectFilePath($manifest, 'Beta', $filename);
        expect($path)->not->toBeNull()->and(is_file($path))->toBeTrue();
    });

    // -----------------------------------------------------------------
    // Slug collision fail-fast.
    // -----------------------------------------------------------------

    test('two distinct project names colliding on the same slug abort the publish and retain the prior generation', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $baseline = $publisher->publish(['Alpha']);

        // Distinct names, identical slug: Str::slug collapses both to
        // 'my-project', which would make the two sets write over each other.
        waspMakeTheme('Collision Theme One', ['My Project']);
        waspMakeTheme('Collision Theme Two', ['My-Project']);

        expect(fn () => $publisher->publish(['My Project', 'My-Project']))
            ->toThrow(RuntimeException::class, 'slug collision');

        // No pointer movement, no staging left behind, prior set intact.
        expect(file_get_contents(LegacyWidgetArtifactSetPublisher::activePointerPath()))->toBe($baseline->generationId);
        expect(File::directories(LegacyWidgetArtifactSetPublisher::stagingRoot()))->toBe([]);
        expect(is_dir(LegacyWidgetArtifactSetPublisher::generationPath($baseline->generationId)))->toBeTrue();
    });

    // -----------------------------------------------------------------
    // Sweep failure after a successful pointer switch.
    // -----------------------------------------------------------------

    test('a sweep failure after the pointer switch does not fail the committed publish', function () {
        $theme = waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $publisher->publish(['Alpha']);

        // Plant an unreachable generation directory so the post-switch
        // mark/sweep will try to delete it, and make exactly that deletion
        // throw; every other File::deleteDirectory call runs for real.
        $fakeDir = waspRootPath() . '/00000000T000000Z-deadbeef';
        File::ensureDirectoryExists($fakeDir);

        $realFs = new \Illuminate\Filesystem\Filesystem();
        $mock = File::partialMock();
        $mock->shouldReceive('deleteDirectory')
            ->with($fakeDir)
            ->andThrow(new RuntimeException('injected sweep failure'));
        $mock->shouldReceive('deleteDirectory')
            ->withAnyArgs()
            ->andReturnUsing(static fn (...$args) => $realFs->deleteDirectory(...$args));

        $theme->update(['content' => '<section><h2>swept v2</h2></section>']);

        // The publish itself must complete: manifest returned, pointer
        // switched to the new generation — the sweep failure is reported,
        // not propagated (the next lock-holding run retries the sweep).
        $manifest = $publisher->publish(['Alpha']);

        expect(file_get_contents(LegacyWidgetArtifactSetPublisher::activePointerPath()))
            ->toBe($manifest->generationId);
        expect(is_dir($fakeDir))->toBeTrue();
    });

    // -----------------------------------------------------------------
    // Long-lived-worker (Octane-style) resolver freshness.
    // -----------------------------------------------------------------

    test('a long-lived worker resolver sees the new generation after a switch without being recreated', function () {
        config()->set('ispa-cms.folder_name', 'Alpha');
        $theme = waspMakeTheme('Alpha Theme', ['Alpha'], '<section><h2>worker v1</h2></section>');

        // ONE resolver instance for the whole "worker lifetime": created
        // once, reused across both requests exactly like an Octane worker
        // would reuse it.
        $resolver = new WidgetTemplatePathResolver();
        $publisher = new LegacyWidgetArtifactSetPublisher();

        $genA = $publisher->publish(['Alpha']);

        $first = $resolver->resolve($theme->widget_id, $theme->id);
        expect($first)->not->toBeNull()
            ->and($first)->toContain($genA->generationId)
            ->and(file_get_contents($first))->toContain('worker v1');

        // Same-generation repeat resolution serves the generation-keyed
        // cached value (identical result, proving the cache is in play).
        expect($resolver->resolve($theme->widget_id, $theme->id))->toBe($first);

        // The "next request" mutates and republishes within the SAME
        // process: the publisher switch calls clearCache() and the cache
        // key carries the observed generation id, so the very next
        // resolution must reflect generation B with no stale entry.
        $theme->update(['content' => '<section><h2>worker v2</h2></section>']);
        $genB = $publisher->publish(['Alpha']);
        expect($genB->generationId)->not->toBe($genA->generationId);

        $second = $resolver->resolve($theme->widget_id, $theme->id);
        expect($second)->not->toBeNull()
            ->and($second)->not->toBe($first)
            ->and($second)->toContain($genB->generationId)
            ->and(file_get_contents($second))->toContain('worker v2');
    });

    // -----------------------------------------------------------------
    // Ownership: no world-writable generation directories or files.
    // -----------------------------------------------------------------

    test('generation directories and files are created without world-writable permissions', function () {
        waspMakeTheme('Alpha Theme', ['Alpha']);

        $publisher = new LegacyWidgetArtifactSetPublisher();
        $manifest = $publisher->publish(['Alpha']);

        $generationDir = LegacyWidgetArtifactSetPublisher::generationPath($manifest->generationId);

        $paths = [
            waspRootPath(),
            $generationDir,
            LegacyWidgetArtifactSetPublisher::activePointerPath(),
        ];
        foreach (File::directories($generationDir) as $dir) {
            $paths[] = $dir;
        }
        foreach (File::allFiles($generationDir) as $file) {
            $paths[] = $file->getPathname();
        }

        expect(count($paths))->toBeGreaterThanOrEqual(5);

        foreach ($paths as $path) {
            clearstatcache(true, $path);
            expect((fileperms($path) & 0o002) === 0)->toBeTrue("World-writable path: {$path}");
        }
    });
}
