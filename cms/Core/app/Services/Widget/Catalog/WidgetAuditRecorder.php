<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\Catalog;

use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Widget;

/**
 * Writes widget catalog events (clone, design save, block, unblock, automatic manifest approval, hub download and update) to the Core
 * audit log.
 *
 * Request data (ip, agent, url) is attached only when a real HTTP request is running, so console
 * and queue runs stay valid with those columns left null.
 */
class WidgetAuditRecorder
{
    public const ACTION_CLONED = 'widget.cloned';
    public const ACTION_DESIGN_SAVED = 'widget.design_saved';
    public const ACTION_BLOCKED = 'widget.blocked';
    public const ACTION_UNBLOCKED = 'widget.unblocked';
    /** A widget placed, previewed or published in Site Layout had its manifest approved automatically. */
    public const ACTION_AUTO_APPROVED = 'widget.auto_approved';
    /** A hub widget was downloaded into this site (widget hub distribution). */
    public const ACTION_DOWNLOADED = 'widget.downloaded';
    /** A downloaded hub widget was updated to a newer hub version. */
    public const ACTION_UPDATED = 'widget.updated';

    /**
     * @param array<string, mixed> $details Stored as the audit row `options`
     */
    public function record(string $action, Widget $widget, int $userId, array $details = []): void
    {
        $request = app()->runningInConsole() ? null : request();

        AuditLog::query()->create([
            'action' => $action,
            'target_type' => 'widget',
            'target_id' => $widget->getKey(),
            'user_id' => $userId,
            'options' => $details,
            'ip_address' => $request?->ip(),
            'agent' => $request?->userAgent(),
            'url' => $request === null ? null : mb_substr($request->fullUrl(), 0, 1000),
        ]);
    }
}
