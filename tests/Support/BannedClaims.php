<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * public claims the.tech release plan bans on every iSPA surface until they are true
 * (release plan section 3 and the open-source release plan's global constraints).
 */
final class BannedClaims
{
    /**
     * Phrases matched case-insensitively.
     *
     * @var list<string>
     */
    public const PHRASES = [
        '100% free and open-source',
        '100% open-source',
        'multi-tenant',
        'single installation',
        'laravel 11',
        'production-ready',
        'enterprise-grade',
        'autonomous',
    ];

    /**
     * Banned phrases found in the views and lang files of one project folder.
     *
     * @param  string  $projectDir  absolute path of `projects/<Folder>`
     * @param  list<string>  $extra  project-specific phrases checked on top of {@see self::PHRASES}
     * @return array<string, list<string>> path relative to the project folder => lower-case phrases found
     */
    public static function scan(string $projectDir, array $extra = []): array
    {
        $phrases = array_values(array_unique(array_map('strtolower', [...self::PHRASES, ...$extra])));
        $found = [];

        foreach (['views', 'lang'] as $folder) {
            $root = rtrim($projectDir, '/') . '/' . $folder;
            if (! is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'json'], true)) {
                    continue;
                }

                $text = (string) file_get_contents($file->getPathname());
                $hits = array_values(array_filter($phrases, static fn (string $phrase): bool => stripos($text, $phrase) !== false));
                if ($hits !== []) {
                    $found[substr($file->getPathname(), strlen(rtrim($projectDir, '/')) + 1)] = $hits;
                }
            }
        }

        ksort($found);

        return $found;
    }
}
