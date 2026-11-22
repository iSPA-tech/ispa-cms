<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

/**
 * Dispatched when a user's password is changed.
 *
 * Carries the user-agent string, IP address, and the affected User model
 * so listeners can send security notifications about the password change.
 *
 * @package Cms\Core\Events
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PasswordChangeNotification
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $agent
     * @param string $ip
     * @param ?User $user
     */
    public function __construct(
        public string $agent,
        public string $ip,
        public ?User  $user
    )
    {
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
