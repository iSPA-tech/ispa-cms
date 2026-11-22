<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use App\Events\SendEmail;
use App\Events\SaveTracking;
use Cms\Core\Events\SendCodeVerify;
use App\Listeners\ApiLogHandler;
use App\Events\OrderCreatedEvent;
use App\Listeners\SendEmailHandle;
use App\Listeners\SaveTrackingHandle;
use Illuminate\Auth\Events\Registered;
use App\Events\SendConfirmToContactor;
use App\Listeners\SendCodeVerifyHandle;
use Cms\Core\Events\PasswordChangeNotification;
use App\Listeners\UserOnOrderCreatedListener;
use App\Listeners\AdminOnOrderCreatedListener;
use App\Listeners\SendConfirmToContactorHandle;
use Cms\Core\Events\SendEmailPasswordAutoResetEvent;
use App\Listeners\SendNotifyNewContactToAdminHandle;
use App\Listeners\PasswordChangeNotificationHandler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use App\Listeners\SendEmailPasswordAutoResetListener;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        SendEmail::class => [
            SendEmailHandle::class,
        ],
        SaveTracking::class => [
            SaveTrackingHandle::class,
        ],
        SendConfirmToContactor::class => [
            SendConfirmToContactorHandle::class,
            SendNotifyNewContactToAdminHandle::class,
        ],
        SendCodeVerify::class => [
            SendCodeVerifyHandle::class,
        ],
        PasswordChangeNotification::class => [
            PasswordChangeNotificationHandler::class,
        ],
        RequestHandled::class => [
            ApiLogHandler::class,
        ],
        //
        SendEmailPasswordAutoResetEvent::class => [
            SendEmailPasswordAutoResetListener::class,
        ],
        OrderCreatedEvent::class => [
            AdminOnOrderCreatedListener::class,
            UserOnOrderCreatedListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
