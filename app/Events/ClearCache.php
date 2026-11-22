<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClearCache
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, SerializesModels;

    public $tags;

    public $key;

    /**
     * AuditLog constructor
     *-------------------
     *
     * @author malayvuong
     *
     * @param  string  $cacheKey
     * @param  string  $type
     * @param  array|string  $tags
     *
     **/
    public function __construct($key = null, $tags = null)
    {
        $this->key = $key;
        $this->type = config('cache.default', 'file');
        $this->tags = $tags;
    }

    /**
     * Get the channels the event should be broadcast on.
     *
     * @return array
     *
     * @author Sang Nguyen
     */
    public function broadcastOn()
    {
        return [];
    }
}
