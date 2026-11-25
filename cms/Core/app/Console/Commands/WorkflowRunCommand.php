<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Console\Command;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Services\Workflow\Exceptions\WorkflowAlreadyRunning;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectInactive;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotRunnable;

/**
 * Start one run of an authored workflow project.
 *
 * Usage: php artisan cms:workflow-run church-stories --user=<user uuid or email>
 * The run acts for the given user, who must hold the project's update ability.
 *
 * @see WorkflowProjectRunService
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   2026.11 (2026-11-24)
 */
class WorkflowRunCommand extends Command
{
    protected $signature = 'cms:workflow-run
        {slug : Workflow project slug}
        {--user= : UUID or email of the user the run acts for}';

    protected $description = 'Start one run of a workflow project and queue it.';

    public function handle(WorkflowProjectRunService $runService): int
    {
        $project = WorkflowProject::query()->where('slug', (string) $this->argument('slug'))->first();
        if (! $project) {
            $this->error('Workflow project not found.');

            return self::FAILURE;
        }

        $userKey = trim((string) $this->option('user'));
        // Compare uuid only with a uuid-shaped value: PostgreSQL refuses to cast other text to its uuid column type.
        $user = $userKey === '' ? null : User::query()
            ->when(Str::isUuid($userKey), static fn ($query) => $query->where('uuid', $userKey), static fn ($query) => $query->where('email', $userKey))
            ->first();

        if (! $user) {
            $this->error('Pass --user=<uuid or email> of the user the run acts for.');

            return self::FAILURE;
        }

        if (! $user->can('update', $project)) {
            $this->error('That user may not run this workflow project.');

            return self::FAILURE;
        }

        try {
            $instance = $runService->start($project, $user, [], 'cli');
        } catch (WorkflowAlreadyRunning|WorkflowProjectInactive|WorkflowProjectNotRunnable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Queued workflow run #{$instance->id} for project {$project->slug}.");

        return self::SUCCESS;
    }
}
