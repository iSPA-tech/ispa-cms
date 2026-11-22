<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Services\Agentic\AgenticPreflightChecker;

/**
 * Validates that all agentic runtime provisioning prerequisites are available.
 *
 * Uses AgenticPreflightChecker to inspect both sync readiness (required DB tables for
 * tool syncing) and grant readiness (required DB tables for permission grants). Reports
 * which tables are missing and which tool service classes were resolved or invalid.
 * Returns FAILURE when any missing table or invalid service is detected, making it
 * suitable for use in deployment pipelines or CI before running cms:agentic-sync-tools.
 *
 * Usage: php artisan cms:agentic-preflight
 * Options:
 *   --service      Specific tool service classes to validate (repeatable)
 *   --environment  Override environment for discovery validation
 *
 * @see    \Cms\Core\Services\Agentic\AgenticPreflightChecker
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticPreflightCommand extends Command
{
    protected $signature = 'cms:agentic-preflight
                            {--service=* : Specific tool service classes to validate}
                            {--environment= : Override environment for discovery validation}';

    protected $description = 'Validate that agentic runtime provisioning prerequisites are available.';

    /**
     * Execute the console command.
     *
     * Inspects sync and grant readiness via AgenticPreflightChecker, prints a status
     * line for each check, and returns FAILURE if any check fails.
     *
     * @param AgenticPreflightChecker $checker Preflight inspection service
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(AgenticPreflightChecker $checker): int
    {
        $syncInspection = $checker->inspectSyncReadiness(
            services: (array) $this->option('service'),
            environment: $this->option('environment') ?: null,
        );
        $grantInspection = $checker->inspectGrantReadiness();

        if ($syncInspection['missing_tables'] === [] && $grantInspection['missing_tables'] === []) {
            $this->info('Agentic provisioning tables are ready.');
        } else {
            if ($syncInspection['missing_tables'] !== []) {
                $this->error('Missing sync tables: ' . implode(', ', $syncInspection['missing_tables']));
            }

            if ($grantInspection['missing_tables'] !== []) {
                $this->error('Missing grant tables: ' . implode(', ', $grantInspection['missing_tables']));
            }
        }

        if ($syncInspection['resolved_services'] === []) {
            $this->warn('No tool services resolved for validation.');
        } else {
            foreach ($syncInspection['resolved_services'] as $className) {
                $this->line("Validated tool service: {$className}");
            }
        }

        if ($syncInspection['invalid_services'] !== []) {
            foreach ($syncInspection['invalid_services'] as $error) {
                $this->error($error);
            }
        }

        $hasFailures = $syncInspection['missing_tables'] !== []
            || $grantInspection['missing_tables'] !== []
            || $syncInspection['invalid_services'] !== [];

        if ($hasFailures) {
            $this->error('Agentic preflight failed.');

            return self::FAILURE;
        }

        $this->info('Agentic preflight passed.');

        return self::SUCCESS;
    }
}
