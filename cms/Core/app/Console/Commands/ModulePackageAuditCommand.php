<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Packages\PackageBuilder;
use Cms\Core\Services\Modules\Packages\PackageBuildException;
use Illuminate\Console\Command;

/**
 * Reports, for every module folder, whether it can be packaged today and the first reason it cannot.
 *
 * Runs the builder's refusal checks only: nothing is written, nothing is signed and no key is needed.
 */
class ModulePackageAuditCommand extends Command
{
    protected $signature = 'cms:module-package-audit
        {--modules-path= : Folder holding the module folders (default base_path(\'cms\'))}
        {--lock= : composer.lock to check requirements against (default base_path(\'composer.lock\'))}
        {--json : Print a JSON list with one object per module}';

    protected $description = 'List every module with whether it can be packaged and the first refusal code and message.';

    /**
     * Check every module folder; the exit code is always success (the audit is a report).
     */
    public function handle(PackageBuilder $builder): int
    {
        $root = rtrim(trim((string) $this->option('modules-path')) ?: base_path('cms'), '/\\');
        $lock = trim((string) $this->option('lock')) ?: null;
        $names = array_values(array_filter(scandir($root) ?: [], static fn (string $n): bool => $n[0] !== '.' && is_dir($root . '/' . $n)));
        sort($names, SORT_STRING);

        $rows = [];
        foreach ($names as $name) {
            try {
                $builder->check($name, $root, $lock);
                $rows[] = ['module' => $name, 'ok' => true, 'code' => '', 'message' => ''];
            } catch (PackageBuildException $e) {
                $code = str_replace('MODULE_PACKAGE_BUILD_', '', $e->errorCode);
                $rows[] = ['module' => $name, 'ok' => false, 'code' => $code, 'message' => $e->getMessage()];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->table(['Module', 'OK', 'Reason code', 'Message'], array_map(
            static fn (array $r): array => [$r['module'], $r['ok'] ? 'yes' : 'no', $r['code'], $r['message']],
            $rows,
        ));
        $ok = count(array_filter($rows, static fn (array $r): bool => $r['ok']));
        $this->info(sprintf('%d of %d modules can be packaged.', $ok, count($rows)));

        return self::SUCCESS;
    }
}
