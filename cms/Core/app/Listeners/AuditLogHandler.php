<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Listeners;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Interfaces\AuditLogInterface;

/**
 * Listener that persists AuditLogEvent payloads via the AuditLog repository.
 * @package Cms\Core\Listeners
 */
class AuditLogHandler
{
    /**
     * AuditLogHandler constructor
     *
     * @param AuditLogInterface $logs
     *
     * @author malayvuong
     */
    public function __construct(protected AuditLogInterface $logs)
    {
        
    }

    /**
     * Handle the event
     *
     * @param AuditLogEvent $event
     * @return void
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function handle(AuditLogEvent $event): void
    {
        $data = [
            'action' => 'action.' . $event->action,
            'changed' => $event->changed,
            'target_type' => $event->type,
            'target_id' => $event->id,
            'ip_address' => request()->ip(),
            'agent' => request()->userAgent(),
            'url' => request()->header('Referer'),
            'options' => [
                'icon' => $event->icon,
                'color' => $event->color,
                'method' => request()->method(),
            ],
            'user_id' => $event->user_id ?? null,
        ];

        $this->logs->create($data);
    }
}
