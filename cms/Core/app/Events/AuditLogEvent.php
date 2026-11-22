<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event representing an audit log entry to be persisted.
 * Carries action metadata, target model reference, UI hints (icon/color), and actor id.
 * @package Cms\Core\Events
 */
class AuditLogEvent
{
    use Dispatchable, SerializesModels;

    /**
     * AuditLog constructor
     *
     * @param string $action
     * @param array $changed
     * @param string $type
     * @param int $id
     * @param string|null $icon
     * @param string|null $color
     * @param int|null $user_id
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function __construct(
        public string   $action,
        public array    $changed,
        public string   $type,
        public int      $id,
        public ?string  $icon = null,
        public ?string  $color = null,
        public null|int $user_id = 0
    )
    {
        $this->user_id = $user_id > 0 ? $user_id : user('id');
    }

    /**
     * Get the channels the event should be broadcast on.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        return [];
    }
}
