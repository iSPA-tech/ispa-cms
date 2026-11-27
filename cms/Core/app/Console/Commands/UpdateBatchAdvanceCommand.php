<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\UpdateBatch\UpdateBatchRunner;
use Illuminate\Console\Command;

/**
 * Moves the open upgrade-all run on (spec 2026-11-26). Scheduled every minute in its own background process while a
 * run is open, so widget and workflow items never run in a process that just installed a module.
 */
class UpdateBatchAdvanceCommand extends Command
{
    /** Command name the scheduler runs. */
    public const NAME = 'cms:update-batch-advance';

    protected $signature = 'cms:update-batch-advance';

    protected $description = 'Move the open "upgrade all" run on: start the next module install or run the next widget/workflow update.';

    public function handle(UpdateBatchRunner $runner): int
    {
        $runner->advance();

        return self::SUCCESS;
    }
}
