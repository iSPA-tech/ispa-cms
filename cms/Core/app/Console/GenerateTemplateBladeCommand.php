<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Throwable;
use Illuminate\Console\Command;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

/**
 * Thin operator entry point over {@see LegacyWidgetArtifactSetPublisher}.
 *
 * Rebuilds EVERY known project's complete Widget/Theme set in one operation
 * (equivalent to treating every project as "affected") under the global
 * active-generation topology (docs/architecture/adr-widget-artifact-store.md):
 * one lock, full verification, and an atomic pointer switch only after the
 * whole graph passes. Direct per-file `file_put_contents` writes and the
 * cross-project stale sweep this command used to perform were retired in
 * Gate 0 Task 2 in favor of that publication topology.
 *
 * Usage: php artisan cms:generate-widget-template
 *
 * @see    LegacyWidgetArtifactSetPublisher
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class GenerateTemplateBladeCommand extends Command
{
    protected $signature = 'cms:generate-widget-template';
    protected $description = 'Generate template blade files for widgets';

    public function __construct(private readonly LegacyWidgetArtifactSetPublisher $publisher)
    {
        parent::__construct();
    }

    /**
     * @return int Command::SUCCESS or Command::FAILURE.
     */
    public function handle(): int
    {
        $this->info('Publishing widget template artifacts...');

        try {
            $manifest = $this->publisher->publish($this->publisher->allKnownProjectNames());
        } catch (Throwable $e) {
            $this->error('✗ Widget template publication failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($manifest->projectNames() as $projectName) {
            $this->line("Published project set: {$projectName}");
        }

        $this->info('Template blade files generated successfully (generation ' . $manifest->generationId . ').');

        return self::SUCCESS;
    }
}
