<?php

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Generator;

use InvalidArgumentException;

/**
 * The module template of `cms:make-module`: a module tree written with placeholder names, plus `manifest.php`, which
 * returns the files each mode adds (`['lite' => [...], 'normal' => [...], 'full' => [...]]`). Modes are cumulative.
 *
 * A file that differs by mode carries line markers: a line containing `@mode <modes>` (comma-separated) opens a block
 * kept only for those modes, a line containing `@endmode` closes it, and both marker lines are always dropped. The
 * stored file keeps every block, so it stays valid code as stored.
 */
final class ModuleTemplate
{
    public const MODES = ['lite', 'normal', 'full'];

    /** Template files that describe the template and are never copied. */
    public const OWN_FILES = ['manifest.php', 'README.md'];

    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Template-relative paths of the files a mode writes, in manifest order.
     *
     * @return list<string>
     */
    public function files(string $mode): array
    {
        $this->assertMode($mode);
        $manifest = require $this->root . '/manifest.php';
        $files = [];
        foreach (self::MODES as $each) {
            array_push($files, ...($manifest[$each] ?? []));
            if ($each === $mode) {
                break;
            }
        }

        return $files;
    }

    /** Apply the mode markers of one file's content. */
    public function render(string $content, string $mode): string
    {
        $this->assertMode($mode);
        $kept = [];
        $keep = true;
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/@mode\s+([a-z,\s]+?)\s*(?:-->|--}}|\*\/)?\s*$/', $line, $m) === 1) {
                $modes = array_map('trim', explode(',', $m[1]));
                $keep = in_array($mode, $modes, true);

                continue;
            }
            if (str_contains($line, '@endmode')) {
                $keep = true;

                continue;
            }
            if ($keep) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private function assertMode(string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown mode \"{$mode}\"; use lite, normal or full.");
        }
    }
}
