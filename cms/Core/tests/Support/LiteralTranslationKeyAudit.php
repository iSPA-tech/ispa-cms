<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * finds translation keys written as literals in PHP (`__('mgs.module_activated')`, `trans(...)`, `@lang`,
 * `Lang::get(...)`) that no translation row or lang file carries, so a screen would show the raw key.
 *
 * A literal counts as a key when it is `group.part[.part]` (optionally `module::` first) and its group is a known
 * group (a lang file name or a sidecar group). Dynamic keys (built from variables) are not literals and are skipped.
 * A key has a row when any sidecar or export (`database/seeders/sql/translate*.php`) names it, as `name` or as
 * `group_name.name`, or when a lang file (`lang/<locale>/<group>.php`) holds it. Pure PHP, no framework.
 */
final class LiteralTranslationKeyAudit
{
    /** @var array<string, true> keys that have a vi row or lang entry */
    private array $vi = [];

    /** @var array<string, true> keys that have an en row or lang entry */
    private array $en = [];

    /** @var array<string, true> */
    private array $groups = [];

    /** @var array<string, array{vi: string, en: string}> texts of the rows and lang entries, by key */
    private array $texts = [];

    public function __construct(private readonly string $root)
    {
        $this->loadRows();
        $this->loadLangFiles();
    }

    /**
     * Literal keys without a row, as `key => list of file:line` (files relative to the root).
     *
     * @param bool $english report keys that miss the English text instead of the Vietnamese one
     *
     * @return array<string, list<string>>
     */
    public function missing(bool $english = false): array
    {
        $have = $english ? $this->en : $this->vi;
        $missing = [];
        foreach ($this->literals() as $key => $places) {
            if (! isset($have[$key])) {
                $missing[$key] = $places;
            }
        }
        ksort($missing);

        return $missing;
    }

    /**
     * Whether $key has both a Vietnamese and an English text (a row or a lang entry).
     */
    public function hasText(string $key): bool
    {
        return isset($this->vi[$key], $this->en[$key]);
    }

    /**
     * The texts of an existing key written with hyphens where $key has underscores (or the other way round), as a hint
     * for a missing key; null when there is none.
     *
     * @return array{vi: string, en: string}|null
     */
    public function variantText(string $key): ?array
    {
        foreach ([str_replace('_', '-', $key), str_replace('-', '_', $key)] as $variant) {
            if ($variant !== $key && isset($this->texts[$variant])) {
                return $this->texts[$variant];
            }
        }

        return null;
    }

    /**
     * Every literal key in the scanned PHP files.
     *
     * @return array<string, list<string>>
     */
    public function literals(): array
    {
        $found = [];
        foreach ($this->phpFiles() as $file) {
            $text = (string) file_get_contents($file);
            // Calls with a literal key, and any `mgs.…` literal (a message key is often kept in a variable first).
            $found2 = [];
            $patterns = [
                '/(?:\b__|\btrans|\btrans_choice|\bLang::get|\bLang::choice|@lang)\(\s*([\'"])((?:[A-Za-z0-9_\-]+::)?[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)+)\1/',
                '/([\'"])(mgs(?:\.[A-Za-z0-9_\-]+)+)\1/',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[2] as [$k, $o]) {
                        $found2[$o] = $k;
                    }
                }
            }
            foreach ($found2 as $offset => $key) {
                $bare = str_contains($key, '::') ? substr($key, (int) strpos($key, '::') + 2) : $key;
                if (preg_match('/[_\-]$/', $bare)) {
                    continue; // a prefix: the rest of the key is built at run time
                }
                if (! isset($this->groups[strtok($bare, '.')])) {
                    continue;
                }
                $line = substr_count(substr($text, 0, (int) $offset), "\n") + 1;
                $found[$bare][] = substr($file, strlen($this->root) + 1) . ':' . $line;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $dirs = [$this->root . '/app'];
        foreach (glob($this->root . '/cms/*/app', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[] = $dir;
        }
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    private function loadRows(): void
    {
        $files = glob($this->root . '/database/seeders/sql/translate*.php') ?: [];
        foreach (glob($this->root . '/cms/*/database/seeders/sql/translate*.php') ?: [] as $file) {
            $files[] = $file;
        }
        // Legacy conversion inputs (`translates.sql`): any key named there counts as having a row.
        foreach (glob($this->root . '/cms/*/database/seeders/sql/translates.sql') ?: [] as $sql) {
            if (preg_match_all("/'((?:[A-Za-z0-9_\\-]+)(?:\\.[A-Za-z0-9_\\-]+)+)'/", (string) file_get_contents($sql), $names)) {
                foreach ($names[1] as $name) {
                    $this->groups[strtok($name, '.')] = true;
                    $this->vi[$name] = true;
                    $this->en[$name] = true;
                }
            }
        }
        foreach ($files as $file) {
            $rows = (static function (string $__file): mixed {
                return include $__file;
            })($file);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['name'])) {
                    continue;
                }
                $name = (string) $row['name'];
                $group = (string) ($row['group_name'] ?? '');
                $keys = [$name];
                if ($group !== '' && ! str_starts_with($name, $group . '.')) {
                    $keys[] = $group . '.' . $name;
                }
                $text = is_array($row['text'] ?? null) ? $row['text'] : [];
                foreach ($keys as $key) {
                    $this->groups[strtok($key, '.')] = true;
                    $this->texts[$key] = ['vi' => (string) ($text['vi'] ?? ''), 'en' => (string) ($text['en'] ?? '')];
                    if (trim((string) ($text['vi'] ?? '')) !== '') {
                        $this->vi[$key] = true;
                    }
                    if (trim((string) ($text['en'] ?? '')) !== '') {
                        $this->en[$key] = true;
                    }
                }
            }
        }
    }

    private function loadLangFiles(): void
    {
        $dirs = [$this->root . '/lang'];
        foreach (glob($this->root . '/cms/*/lang', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[] = $dir;
        }
        foreach (glob($this->root . '/cms/*/resources/lang', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[] = $dir;
        }
        foreach ($dirs as $dir) {
            foreach (['vi' => 'vi', 'en' => 'en'] as $locale => $_) {
                foreach (glob($dir . '/' . $locale . '/*.php') ?: [] as $file) {
                    $data = (static function (string $__file): mixed {
                        return include $__file;
                    })($file);
                    if (! is_array($data)) {
                        continue;
                    }
                    $group = basename($file, '.php');
                    $this->groups[$group] = true;
                    $this->flatten($group, $data, $locale);
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function flatten(string $prefix, array $data, string $locale): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value)) {
                if ($value !== []) {
                    // A key that names a whole group of texts (`__('x.fields')` returning an array) counts as present.
                    $locale === 'vi' ? $this->vi[$path] = true : $this->en[$path] = true;
                }
                $this->flatten($path, $value, $locale);

                continue;
            }
            if (trim((string) $value) === '') {
                continue;
            }
            $this->texts[$path][$locale] = (string) $value;
            $this->texts[$path] += ['vi' => '', 'en' => ''];
            if ($locale === 'vi') {
                $this->vi[$path] = true;
            } else {
                $this->en[$path] = true;
            }
        }
    }
}
