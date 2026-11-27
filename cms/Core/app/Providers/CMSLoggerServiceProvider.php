<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Providers;

use InvalidArgumentException;
use Illuminate\Support\ServiceProvider;
use App\Services\Logger\DiscordService;
use App\Services\Logger\TelegramService;

/**
 * Registers the CMSLogger singleton with a configurable logging provider.
 *
 * Binds: 'CMSLogger' singleton resolved to either TelegramService or DiscordService
 * based on the LOGGER_PROVIDER config/env value.
 * Boots: No boot-time actions.
 *
 * @see \App\Services\Logger\TelegramService
 * @see \App\Services\Logger\DiscordService
 * @package Cms\Core\Providers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CMSLoggerServiceProvider extends ServiceProvider
{
    /**
     * Register the CMSLogger singleton into the service container.
     *
     * Resolves the logger provider from configuration (telegram or discord) and binds
     * the corresponding service class as a singleton under the 'CMSLogger' key.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When an unsupported logger provider is configured.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function register(): void
    {
        // Resolved when first used (after boot), so the provider chosen in the admin settings (notify.LOGGER_PROVIDER,
        // applied to the config at boot by AppServiceProvider) is the one that logs.
        $this->app->singleton('CMSLogger', function () {
            $provider = (string) config('logging.logger_provider', env('LOGGER_PROVIDER', 'telegram'));

            $loggerClass = match (strtolower($provider)) {
                'telegram' => TelegramService::class,
                'discord' => DiscordService::class,
                default => throw new InvalidArgumentException("Unsupported logger provider: $provider"),
            };

            return new $loggerClass;
        });
    }

    /**
     * Boot the service provider.
     *
     * No boot-time actions are required for the CMS logger.
     *
     * @return void
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function boot(): void
    {
        // No boot logic required unless you want.
    }
}
