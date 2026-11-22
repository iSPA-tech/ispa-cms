<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Services\Widget\WidgetDefinitionSyncService;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;

/**
 * Operator CLI recovery for a `sync_status: pending` legacy Widget
 * definition sync incident (Gate 0 Task 2). Re-runs the export and artifact
 * publication for every project the publisher currently knows about; safe to
 * run repeatedly (the underlying publisher is idempotent under its global
 * lock and its no-duplicate-retry check).
 *
 * The protected admin action `POST /api/v2/admin/widget/retry-sync`
 * (WidgetController::retrySync) exercises the same
 * {@see WidgetDefinitionSyncService::syncAll()} path, so operators are never
 * limited to this shell command as their only remedy.
 *
 * Usage: php artisan cms:retry-widget-artifact-publication
 *
 * @package Cms\Core\Console\Commands
 */
class RetryWidgetArtifactPublicationCommand extends Command
{
    protected $signature = 'cms:retry-widget-artifact-publication';
    protected $description = 'Retry the legacy Widget definition export and artifact publication after a pending sync_status incident.';

    public function __construct(
        private readonly WidgetDefinitionSyncService $syncService,
        private readonly LegacyWidgetArtifactSetPublisher $publisher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Retrying widget definition export and artifact publication...');

        $result = $this->syncService->syncAll();

        if ($result->isPending()) {
            $this->error(sprintf(
                '✗ Still pending. incident_id=%s error_code=%s',
                (string) $result->incidentId,
                (string) $result->errorCode
            ));

            return self::FAILURE;
        }

        // A switching publish already mark/sweeps under its own lock, but a
        // successful no-op retry does not switch; run cleanup explicitly so
        // an operator retry always leaves the generation store swept.
        $removed = $this->publisher->cleanup();
        foreach ($removed as $generationId) {
            $this->line("Swept unreachable generation: {$generationId}");
        }

        $this->info('✓ Widget definition sync is ready.');

        return self::SUCCESS;
    }
}
