<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Providers;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Listeners\AuditLogHandler;
use Cms\Core\Listeners\StartWebMfaChallenge;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Core module event-service provider mapping internal events to listeners.
 * @package Cms\Core\Providers
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        AuditLogEvent::class => [
            AuditLogHandler::class,
        ],
        Login::class => [
            StartWebMfaChallenge::class,
        ],
    ];

    /**
     * Configure the proper event listeners for email verification.
     *
     * @return void
     */
    protected function configureEmailVerification(): void
    {
    }
}
