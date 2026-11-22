<?php

/**
 * Integrity contract for the authored admin translation sidecars.
 *
 * The authored sidecars (`cms/<Module>/database/seeders/sql/translate-admin-*.php`) are the single
 * source of truth for admin wording: on every deploy each authored entry overwrites the production
 * text when it differs. That only works when one key has one wording, so these tests fail when
 *  - the same key is defined more than once, across files or modules, with different en/vi text; or
 *  - a record has no usable name or no text payload.
 *
 * Keys are compared the way the importer matches them (trimmed, case-insensitive), and sidecars that
 * `require` an exported file are covered because the files are loaded, not parsed.
 */

/**
 * Absolute paths of every authored admin sidecar, in a stable order.
 *
 * @return array<int, string>
 */
function sidecarIntegrityFiles(): array
{
    $files = glob(dirname(__DIR__, 2) . '/cms/*/database/seeders/sql/translate-admin-*.php') ?: [];
    sort($files);

    return $files;
}

/**
 * Load one sidecar in an isolated scope so its helper closures never leak between files.
 *
 * @return array<int|string, mixed>
 */
function sidecarIntegrityLoad(string $path): array
{
    $records = (static fn (string $file): mixed => require $file)($path);

    if (! is_array($records)) {
        throw new RuntimeException(sprintf('Sidecar %s must return an array of records.', sidecarIntegrityLabel($path)));
    }

    return $records;
}

/**
 * Short `Module/file` label used in failure messages.
 */
function sidecarIntegrityLabel(string $path): string
{
    return basename(dirname($path, 4)) . '/' . basename($path);
}

/**
 * Decode a record text payload (array or JSON string) into a locale => string map.
 *
 * @return array<string, string>|null Null when the payload is missing or not decodable.
 */
function sidecarIntegrityText(mixed $text): ?array
{
    if (is_string($text)) {
        $text = json_decode($text, true);
    }

    return is_array($text) ? array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $text) : null;
}

it('discovers the authored admin sidecars', function (): void {
    $records = 0;

    foreach (sidecarIntegrityFiles() as $path) {
        $records += count(sidecarIntegrityLoad($path));
    }

    expect(sidecarIntegrityFiles())->not->toBeEmpty()
        ->and($records)->toBeGreaterThan(0);
});

it('gives every authored record a name and a text payload', function (): void {
    $problems = [];

    foreach (sidecarIntegrityFiles() as $path) {
        foreach (sidecarIntegrityLoad($path) as $position => $record) {
            $where = sidecarIntegrityLabel($path) . '#' . $position;
            $name = is_array($record) ? ($record['name'] ?? null) : null;

            if (! is_string($name) || trim($name) === '') {
                $problems[] = $where . ': missing name';

                continue;
            }

            $text = sidecarIntegrityText($record['text'] ?? null);

            if ($text === null || array_filter($text, static fn (string $value): bool => $value !== '') === []) {
                $problems[] = sprintf('%s (%s): missing text', $where, $name);
            }
        }
    }

    expect($problems)->toBe([], implode(PHP_EOL, $problems));
});

it('defines every authored key once or with identical en/vi text', function (): void {
    $definitions = [];

    foreach (sidecarIntegrityFiles() as $path) {
        foreach (sidecarIntegrityLoad($path) as $position => $record) {
            $name = is_array($record) ? ($record['name'] ?? null) : null;
            $text = is_array($record) ? sidecarIntegrityText($record['text'] ?? null) : null;

            if (! is_string($name) || trim($name) === '' || $text === null) {
                continue; // Reported by the record-shape test above.
            }

            $locales = array_filter($text, static fn (string $value): bool => $value !== '');
            ksort($locales);

            $definitions[mb_strtolower(trim($name))][] = [
                'where' => sidecarIntegrityLabel($path) . '#' . $position,
                'name' => trim($name),
                'text' => $locales,
            ];
        }
    }

    $conflicts = [];

    foreach ($definitions as $defined) {
        $distinct = array_unique(array_map(
            static fn (array $definition): string => json_encode($definition['text'], JSON_UNESCAPED_UNICODE),
            $defined
        ));

        if (count($distinct) < 2) {
            continue;
        }

        $conflicts[] = $defined[0]['name'] . ' is defined with different text: ' . implode(' | ', array_map(
            static fn (array $definition): string => $definition['where'] . ' ' . json_encode($definition['text'], JSON_UNESCAPED_UNICODE),
            $defined
        ));
    }

    expect($conflicts)->toBe([], implode(PHP_EOL, $conflicts));
});
