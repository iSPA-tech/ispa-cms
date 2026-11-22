<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Console\Command;
use Cms\Core\Services\Agentic\AgentScheduleService;

/**
 * Queues due agentic schedules for execution.
 *
 * Usage: php artisan cms:agentic-run-schedules {--limit=20}
 * Finds schedules that are due and dispatches them to the queue,
 * up to the configurable limit (default 20).
 *
 * @see \Cms\Core\Services\Agentic\AgentScheduleService
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticRunSchedulesCommand extends Command
{
    protected $signature = 'cms:agentic-run-schedules {--limit=20 : Maximum due schedules to queue}';

    protected $description = 'Queue due agentic schedules for execution.';

    /**
     * Find due agentic schedules and dispatch them to the queue for execution.
     *
     * Respects the configurable --limit option (default 20) and reports the number
     * of schedules queued along with their IDs.
     *
     * @param AgentScheduleService $scheduleService Service that queries and queues due schedules.
     * @return int Command exit code (SUCCESS).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(AgentScheduleService $scheduleService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $scheduleService->queueDue($limit);

        $this->info("Queued {$result['queued']} agentic schedule(s).");

        if (! empty($result['schedule_ids'])) {
            $this->line('Schedule IDs: ' . implode(', ', $result['schedule_ids']));
        }

        return self::SUCCESS;
    }
}
