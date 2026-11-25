<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Illuminate\Console\Command;

/**
 * Takes the safety backup of a core upgrade asked from the admin, as the web user, before the upgrade runner (the
 * code owner) starts it ({@see CoreUpgradeRequests::prepareBackup()}). The scheduler starts it in its own process
 * while an upgrade request waits ({@see \Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher}); it exits at once when
 * there is nothing to do. Once the backup is ready it starts the runner when this user may run it.
 */
class CoreUpgradeBackupCommand extends Command
{
    /** Command name the scheduler starts. */
    public const NAME = 'cms:core-upgrade-backup';

    protected $signature = 'cms:core-upgrade-backup';

    protected $description = 'Take the safety backup of a core upgrade asked from the admin (run by the scheduler).';

    public function handle(CoreUpgradeRequests $requests, BackupGate $gate): int
    {
        $result = $requests->prepareBackup($gate);
        $this->line($result);
        if ($result === 'prepared') {
            // Hand over at once instead of waiting for the next scheduler minute; a site without cron needs this.
            app(CoreRunnerLauncher::class)->startRunner();
        }

        return $result === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
