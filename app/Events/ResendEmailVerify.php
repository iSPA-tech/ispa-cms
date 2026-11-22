<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResendEmailVerify
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @var object|null $user */
    public ?object $user;

    /** @var string|null $url */
    public ?string $url;

    /**
     * Create a new event instance.
     *
     * @param  string  $url
     * @param  null  $user
     * @return void
     */
    public function __construct(string $url, $user = null)
    {
        $this->user = $user;
        $this->url = $url;
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
