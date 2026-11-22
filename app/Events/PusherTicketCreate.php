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
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PusherTicketCreate implements ShouldBroadcast
{
    /** @var bool */
    public bool $isDiscovery = false;


    public array $data;

    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->data = [];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array|Channel|PrivateChannel
     */
    public function broadcastOn(): array|Channel|PrivateChannel
    {
        return ['tickets-reload'];
    }

    public function broadcastAs(): string
    {
        return 'tickets-reload';
    }
}
