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
use Cms\Core\Services\CoreUpgrade\CoreUpgradeFinisher;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

        $this->writeMissingLanguagePacks();

        return self::SUCCESS;
    }

    /**
     * Bridge for an update run by an older updater (2026.11.09 and before): it chose the steps after the swap itself and
     * may not write the language packs of the new VERSION, but it always runs this command once the new files are in
     * place. So while an update is in progress (its private state file) and the admin packs of this version are
     * missing, import the translations and write the packs, as the new version's own steps do. Never during those steps
     * ({@see CoreUpgradeFinisher::FINISHING_ENV}), a deploy or an install: they write the packs themselves.
     */
    private function writeMissingLanguagePacks(): void
    {
        $version = trim((string) config('ispa-cms.app_version', ''));
        if ($version === '' || getenv(CoreUpgradeFinisher::FINISHING_ENV) === '1'
            || ! is_file(base_path(CoreUpgradePreflight::PRIVATE_STATE_FILE))) {
            return;
        }
        try {
            if (is_dir(Storage::disk('public')->path("i18n/{$version}/adm"))) {
                return;
            }
            $this->components->info("Writing the admin language packs of {$version}: this update did not.");
            $ok = $this->call('cms:translation-sync', ['--all' => true]) === self::SUCCESS
                && $this->call('cms:translation-generate', ['--public-only' => true]) === self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $ok = false;
        }
        if (! $ok) {
            $this->components->warn('Could not write the admin language packs; run `php artisan cms:translation-sync --all` and `php artisan cms:translation-generate --public-only`.');
        }
    }
}
