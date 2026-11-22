<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Admin\AdminRuntimeConfig;
use Illuminate\Console\Command;

/**
 * Writes public/iPanel/config.js, the run-time site values of the Vue admin (spec 5.2). Safe to repeat.
 */
class AdminConfigCommand extends Command
{
    protected $signature = 'cms:admin-config {--path= : Write to this file instead of public/iPanel/config.js}';

    protected $description = 'Write the admin run-time config (public/iPanel/config.js) from .env.';

    /**
     * Write the file.
     */
    public function handle(AdminRuntimeConfig $config): int
    {
        if ((string) config('ispa-cms.admin_auth_key', '') === '') {
            $this->components->warn('ISPA_ADMIN_AUTH_KEY is empty in .env; the admin sends an empty key and the API will refuse it.');
        }

        $path = $config->write($this->option('path') ?: null);
        $this->components->info("Admin config written to {$path}");

        return self::SUCCESS;
    }
}
