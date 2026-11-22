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
 * Grants persisted tool definitions to an agent profile.
 *
 * Usage: php artisan cms:agentic-grant-profile-tools {profile} {tool*}
 * Accepts a profile slug and one or more tool definition names. Reports
 * unknown definitions as errors and confirms the count of granted tools.
 *
 * @see \Cms\Core\Services\Agentic\AgentToolProvisioner
 * @see \Cms\Core\Services\Agentic\AgenticPreflightChecker
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticGrantProfileToolsCommand extends Command
{
    protected $signature = 'cms:agentic-grant-profile-tools
                            {profile : Agent profile slug}
                            {tool* : Tool definition names to grant}';

    protected $description = 'Grant persisted agent tool definitions to an agent profile.';

    /**
     * Grant one or more persisted tool definitions to the specified agent profile.
     *
     * Runs a preflight readiness check, then delegates to the provisioner.
     * Reports unknown tool definitions as errors and confirms the granted count on success.
     *
     * @param AgentToolProvisioner $provisioner Service that attaches tool definitions to profiles.
     * @param AgenticPreflightChecker $checker Validates that the grant subsystem is ready.
     * @return int Command exit code (SUCCESS or FAILURE).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(AgentToolProvisioner $provisioner, AgenticPreflightChecker $checker): int
    {
        try {
            $checker->assertGrantReady();

            $result = $provisioner->grantToolsToProfile(
                (string) $this->argument('profile'),
                array_values((array) $this->argument('tool')),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['missing'] !== []) {
            $this->error('Unknown tool definitions: ' . implode(', ', $result['missing']));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Granted %d tool(s) to profile [%s].',
            count($result['granted']),
            $result['profile']->slug
        ));

        return self::SUCCESS;
    }
}
