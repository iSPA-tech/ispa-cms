<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Providers;

use App\Events\TextToSpeechContentRequest;
use App\Events\TranslateContentRequest;
use Cms\Content\Listeners\TextToSpeechContentRequestHandler;
use Cms\Content\Listeners\TranslateContentRequestHandler;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Event service provider for the Content module.
 *
 * Registers event-to-listener mappings. Auto-discovery enabled.
 *
 * @package Cms\Content\Providers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        TranslateContentRequest::class => [
            TranslateContentRequestHandler::class,
        ],
        TextToSpeechContentRequest::class => [
            TextToSpeechContentRequestHandler::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
