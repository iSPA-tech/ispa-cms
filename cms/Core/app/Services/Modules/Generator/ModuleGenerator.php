<?php

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Generator;

use Illuminate\Support\Facades\File;

/**
 * Writes the files of one template mode into a new module folder, renamed for the module and entity names.
 */
final class ModuleGenerator
{
    /** Migration file names in the template start with this date; a generated module gets the current time. */
    private const MIGRATION_DATE = '/^(database\/migrations\/)\d{4}_\d{2}_\d{2}_\d{6}_/';

    public function __construct(private readonly ModuleTemplate $template)
    {
    }

    /**
     * Target path (relative to the module folder) => template path, in manifest order.
     *
     * @return array<string, string>
     */
    public function plan(ModuleNames $names, string $mode): array
    {
        $plan = [];
        $stamp = now()->format('Y_m_d_His');
        foreach ($this->template->files($mode) as $source) {
            $target = (string) preg_replace(self::MIGRATION_DATE, '${1}' . $stamp . '_', $names->rename($source));
            $plan[$target] = $source;
        }

        return $plan;
    }

    /**
     * Write the mode's files into `$moduleDir`. `$extra` holds literal replacements applied after the renaming
     * (e.g. the core version requirement of `module.json`).
     *
     * @param array<string, string> $extra
     * @return list<string> written paths, relative to `$moduleDir`
     */
    public function write(ModuleNames $names, string $mode, string $moduleDir, array $extra = []): array
    {
        $written = [];
        foreach ($this->plan($names, $mode) as $target => $source) {
            $content = $this->template->render(File::get($this->template->root() . '/' . $source), $mode);
            $content = strtr($names->rename($content), $extra);
            File::ensureDirectoryExists(dirname($moduleDir . '/' . $target));
            File::put($moduleDir . '/' . $target, $content);
            $written[] = $target;
        }

        return $written;
    }
}
