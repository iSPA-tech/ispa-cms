<?php

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Guard for fixed ids in the exported admin translation sidecars.
 *
 * An `id` in a sidecar row only links an existing row (see TranslationModuleSeederImporter): when the key is not found
 * by name, the importer falls back to the row that owns that id in the target database. An id that clashes with
 * another key therefore links the wrong row and never creates the key. This is how the passkey sign-in text was left
 * as "Sign In Desc" on the sign-in screen: ids 6938-6942 belong to other keys in some databases.
 *
 * Within the repository the clash can be seen in the data: two sidecars sharing an id, or an id owned by a different
 * key in the Core export that the sidecars are merged into.
 *
 * extends the guard to the authored module sidecars (`cms/<Module>/database/seeders/sql/translate-admin-*.php`).
 * The id fallback never creates or renames a key: it only links the authored row to whatever row owns that id, so the
 * authored key is left missing on screen. On 2026-11-15 those sidecars carried 9,358 distinct ids, 1,328 of them used
 * by two modules for different keys, and the recovered sidecars pointed low ids at real rows of other keys. The rows
 * therefore carry no id at all; the numbers some sidecar helpers still take are authoring references only.
 */

/**
 * Rows of every file matching the glob, as [file, name, id] triples (rows without an id are skipped).
 *
 * @param  string  $pattern  Glob pattern relative to the application base path.
 * @return array<int, array{0: string, 1: string, 2: int}>
 */
function sidecarIdRows(string $pattern): array
{
    $found = [];

    foreach (glob(base_path($pattern)) ?: [] as $file) {
        $rows = require $file;

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['id'], $row['name'])) {
                $found[] = [str_replace(base_path() . '/', '', $file), (string) $row['name'], (int) $row['id']];
            }
        }
    }

    return $found;
}

it('keeps every fixed sidecar id unique and tied to one key', function (): void {
    $sidecars = sidecarIdRows('cms/*/database/seeders/exported/translates-admin-*.php');

    // The glob must still find the sidecars, even though none of them carries an id.
    expect(glob(base_path('cms/*/database/seeders/exported/translates-admin-*.php')))->not->toBeEmpty();

    $owners = [];
    foreach ($sidecars as [$file, $name, $id]) {
        $owners[$id][$name][] = $file;
    }

    $clashes = [];
    foreach ($owners as $id => $names) {
        if (count($names) > 1) {
            $clashes[] = "id {$id} is used by " . implode(', ', array_keys($names));
        }
    }

    expect($clashes)->toBe([], 'Two sidecar keys share a fixed id. Remove the ids: an id only links an existing row.');
});

it('never points a sidecar id at a row that holds a different key', function (): void {
    $sidecars = sidecarIdRows('cms/*/database/seeders/exported/translates-admin-*.php');
    $others = array_merge(
        sidecarIdRows('cms/Core/database/seeders/exported/translates.php'),
        sidecarIdRows('cms/*/database/seeders/sql/translate-admin-*.php'),
    );

    $byId = [];
    foreach ($others as [$file, $name, $id]) {
        $byId[$id][$name] = $file;
    }

    $wrong = [];
    foreach ($sidecars as [$file, $name, $id]) {
        foreach ($byId[$id] ?? [] as $otherName => $otherFile) {
            if ($otherName !== $name) {
                $wrong[] = "{$file}: id {$id} of {$name} is owned by {$otherName} in {$otherFile}";
            }
        }
    }

    expect($wrong)->toBe([], 'A sidecar id points at a row with another key, so the sync would link the wrong row.');
});

it('does not carry the old passkey ids that clashed with the starter pack rows', function (): void {
    $rows = require base_path('cms/Core/database/seeders/exported/translates-admin-passkey.php');

    foreach ($rows as $row) {
        expect($row)->not->toHaveKey('id');
    }
});

it('never gives one id to two keys across the sidecars of every module', function (): void {
    $rows = array_merge(
        sidecarIdRows('cms/*/database/seeders/sql/translate-admin-*.php'),
        sidecarIdRows('cms/*/database/seeders/exported/translates-admin-*.php'),
    );

    $owners = [];
    foreach ($rows as [$file, $name, $id]) {
        $owners[$id][$name][] = $file;
    }

    $clashes = [];
    foreach ($owners as $id => $names) {
        if (count($names) > 1) {
            $clashes[] = "id {$id} is used by " . implode(', ', array_map(
                static fn (string $name, array $files): string => $name . ' (' . implode(', ', array_unique($files)) . ')',
                array_keys($names),
                $names,
            ));
        }
    }

    expect($clashes)->toBe([], 'Two sidecar keys share an id, so one of them is linked to the other row and never created.');
});

it('keeps ids out of the authored module sidecars', function (): void {
    $rows = sidecarIdRows('cms/*/database/seeders/sql/translate-admin-*.php');

    $withId = array_map(static fn (array $row): string => "{$row[0]}: {$row[1]} (id {$row[2]})", $rows);

    expect($withId)->toBe([], 'An authored sidecar row carries an id. Omit it: the importer finds rows by name, and an id only links an existing row, which may hold another key.');
});
