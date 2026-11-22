<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget;

use Throwable;
use RuntimeException;
use Illuminate\Support\Str;
use App\Events\Widget\LegacyWidgetDefinitionChanged;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Services\Widget\DTO\WidgetDefinitionSyncResult;

/**
 * Orchestrates post-commit legacy Widget definition synchronization.
 *
 * Runs after a Widget/Theme/Input database mutation has already committed.
 * Export failure or artifact-publication failure never undoes the commit and
 * never reports HTTP 503: this service always returns a
 * {@see WidgetDefinitionSyncResult}, `ready` on success or `pending` (with an
 * incident id an operator can use to retry) on failure.
 *
 * Chunk 2 Task 15a: every sync attempt -- `ready` or `pending` alike --
 * additionally dispatches the stable root {@see LegacyWidgetDefinitionChanged}
 * event via {@see self::announce()}. This is the ONE place in the codebase
 * where the event can be raised honestly: every Core controller
 * (WidgetController, WidgetThemeController, WidgetInputController) already
 * calls sync()/syncAll() strictly after `DB::commit()`, so dispatching here
 * guarantees the event always fires post-commit without touching any of
 * those (out-of-scope) controllers.
 *
 * @package Cms\Core\Services\Widget
 */
class WidgetDefinitionSyncService
{
    public const ERROR_EXPORT_FAILED = 'WIDGET_EXPORT_FAILED';
    public const ERROR_PUBLISH_FAILED = 'WIDGET_PUBLISH_FAILED';

    public function __construct(
        private readonly LegacyWidgetArtifactSetPublisher $publisher,
        private readonly WidgetAuthoringPolicy $authoring = new WidgetAuthoringPolicy(),
    ) {
    }

    /**
     * @param array<int, string> $affectedProjects Project names whose complete
     *        artifact set must be rebuilt as a result of the mutation that
     *        just committed.
     */
    public function sync(array $affectedProjects): WidgetDefinitionSyncResult
    {
        try {
            $this->export();
        } catch (Throwable $e) {
            return $this->pending($affectedProjects, $e, self::ERROR_EXPORT_FAILED);
        }

        try {
            $this->publisher->publish($affectedProjects);
        } catch (Throwable $e) {
            return $this->pending($affectedProjects, $e, self::ERROR_PUBLISH_FAILED);
        }

        $result = WidgetDefinitionSyncResult::ready();
        $this->announce($affectedProjects, $result);

        return $result;
    }

    /**
     * Retry-safe full resync: rebuild every project the publisher currently
     * knows about (database plus active-manifest projects). Backs both the
     * operator `cms:retry-widget-artifact-publication` command and the
     * protected admin retry action, so a pending `sync_status` incident
     * always has a browser-usable remedy that is never "run a shell command".
     */
    public function syncAll(): WidgetDefinitionSyncResult
    {
        return $this->sync($this->publisher->allKnownProjectNames());
    }

    private function export(): void
    {
        // Only a local authoring environment owns the catalog files; other sites just republish artifacts.
        if (!$this->authoring->authoringEnabled()) {
            return;
        }

        $exitCode = Artisan::call('cms:export-widgets');

        if ($exitCode !== 0) {
            throw new RuntimeException("Widget export failed (exit code {$exitCode}).");
        }
    }

    /**
     * @param array<int, string> $affectedProjects
     */
    private function pending(array $affectedProjects, Throwable $e, string $errorCode): WidgetDefinitionSyncResult
    {
        report($e);

        $incidentId = (string) Str::uuid();

        CMSLogger::error([
            'message' => 'Widget definition sync pending: ' . $e->getMessage(),
            'incident_id' => $incidentId,
            'error_code' => $errorCode,
        ]);

        $result = WidgetDefinitionSyncResult::pending($incidentId, $errorCode);
        $this->announce($affectedProjects, $result);

        return $result;
    }

    /**
     * Dispatch the stable root event after every sync attempt regardless of
     * outcome (plan Chunk 2 Task 15a checklist item 1). Root/Core service:
     * imports only the root `App\Events\Widget\LegacyWidgetDefinitionChanged`
     * event class. This comment deliberately omits the namespace of the
     * module that listens for it: the module-boundary regression suite
     * scans root/app files for that namespace token to keep the dependency
     * direction one-way (docs/architecture/adr-widget-module-boundary.md)
     * -- no such class is imported or referenced anywhere in this file.
     *
     * @param array<int, string> $affectedProjects
     */
    private function announce(array $affectedProjects, WidgetDefinitionSyncResult $result): void
    {
        event(new LegacyWidgetDefinitionChanged($affectedProjects, $result->status, $result->incidentId));
    }
}
