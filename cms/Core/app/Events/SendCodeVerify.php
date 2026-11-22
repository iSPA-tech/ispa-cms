<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched to send a verification code to a user.
 *
 * Carries the user, verification code, and type (e.g. "reset-password")
 * so listeners can deliver the code via email, SMS, or other channels.
 *
 * @package Cms\Core\Events
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SendCodeVerify
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $locale = null;

    /**
     * Create a new event instance.
     *
     * @param  mixed $user
     * @param  string $code
     * @param  string $type
     * @return void
     */
    public function __construct(
        public $user,
        public string $code,
        public string $type = 'reset-password',
    ) {
        $this->locale = app()->getLocale();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn(): Channel|array
    {
        return [];
    }
}
