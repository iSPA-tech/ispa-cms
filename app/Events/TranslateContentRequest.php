<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class TranslateContentRequest
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param int $fromContentId
     * @param int $toContentId
     * @param array $columnNameToTranslate
     * @param string|null $uid
     * @param string|null $token
     */
    public function __construct(
        public int     $fromContentId,
        public int     $toContentId,
        public array   $columnNameToTranslate = [],
        public ?string $uid = null,
        public ?string $token = null,
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
