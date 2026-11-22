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
use Cms\Core\Services\Agentic\AgentToolProvisioner;

/**
 * Discovers and persists agent tool definitions and bindings from configured tool services.
 *
 * Asserts sync readiness via AgenticPreflightChecker (fails fast if DB tables are missing),
 * then delegates to AgentToolProvisioner to discover all tool service classes, extract their
 * tool definitions, and write them to the database. Useful for bootstrapping a fresh agentic
 * environment or refreshing tool definitions after code changes.
 *
 * Usage: php artisan cms:agentic-sync-tools
 * Options:
 *   --service      Specific tool service classes to sync (repeatable)
 *   --environment  Override environment for persisted bindings
 *
 * @see    \Cms\Core\Services\Agentic\AgentToolProvisioner
 * @see    \Cms\Core\Services\Agentic\AgenticPreflightChecker
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticSyncToolsCommand extends Command
{
    protected $signature = 'cms:agentic-sync-tools
                            {--service=* : Specific tool service classes to sync}
                            {--environment= : Override environment for persisted bindings}';

    protected $description = 'Discover and persist agent tool definitions/bindings from configured or explicit tool services.';

    /**
     * Execute the console command.
     *
     * Asserts that the database tables are ready, then syncs tool definitions from all
     * resolved tool service classes and prints a per-service summary.
     *
     * @param AgentToolProvisioner    $provisioner Tool definition sync service
     * @param AgenticPreflightChecker $checker     Preflight readiness checker
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(AgentToolProvisioner $provisioner, AgenticPreflightChecker $checker): int
    {
        try {
            $checker->assertSyncReady(
                services: (array) $this->option('service'),
                environment: $this->option('environment') ?: null,
            );

            $results = $provisioner->syncServices(
                services: (array) $this->option('service'),
                environment: $this->option('environment') ?: null,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->warn('No tool services resolved for sync.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($results as $result) {
            $count = count($result['tool_names']);
            $total += $count;
            $this->line(sprintf(
                'Synced %d tool(s) from %s%s',
                $count,
                $result['class'],
                $result['module_scope'] ? " [module_scope={$result['module_scope']}]" : ''
            ));
        }

        $this->info("Agentic tool sync complete. Persisted {$total} tool definition(s).");

        return self::SUCCESS;
    }
}
