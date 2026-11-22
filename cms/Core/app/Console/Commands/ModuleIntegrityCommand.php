<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Install\ModuleIntegrity;
use Illuminate\Console\Command;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Compares installed runtime module files with the hashes of the signed package they came from and stores the result
 * for the admin modules page and the fleet report. Scheduled daily; also run on demand.
 *
 * Information only: the command never disables a module, never blocks anything and always exits 0, even when files
 * changed or a record is missing.
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-15)
 */
class ModuleIntegrityCommand extends Command
{
    protected $signature = 'cms:module-integrity {--json : Print the result as JSON}';

    protected $description = 'Compare installed module files with the signed package hashes and store the result (information only).';

    /**
     * Run the check.
     *
     * @param ModuleIntegrity $integrity The integrity service.
     * @return int Always 0.
     */
    public function handle(ModuleIntegrity $integrity): int
    {
        $known = [];
        try {
            foreach (ModuleFacade::all() as $module) {
                $known[] = $module->getName();
            }
        } catch (Throwable) {
            // Records alone still give a result.
        }

        $results = $integrity->checkAll($integrity->names($known));
        ModuleIntegrity::store($results);

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($results as $name => $row) {
            if ($row['state'] !== ModuleIntegrity::STATE_UNKNOWN) {
                $rows[] = [$name, $row['version'], $row['state'], $row['changed']];
            }
        }
        $this->table(['Module', 'Version', 'State', 'Changed files'], $rows);
        $this->info(sprintf('%d module(s) compared, %d without a signed record.', count($rows), count($results) - count($rows)));

        return self::SUCCESS;
    }
}
