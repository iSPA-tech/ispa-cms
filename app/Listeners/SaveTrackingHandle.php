<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Events\SaveTracking;
use App\Services\Tracking\TrackingEventDispatcher;

class SaveTrackingHandle
{
    public function __construct(
        private readonly TrackingEventDispatcher $dispatcher
    ) {
    }

    /**
     * Handle the event.
     *
     * @param SaveTracking $event
     *
     * @return void
     */
    public function handle(SaveTracking $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
