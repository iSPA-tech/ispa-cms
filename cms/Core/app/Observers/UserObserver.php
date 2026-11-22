<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Observers;

use Cms\Core\app\Models\User;
use Cms\Core\Observers\AbstractObserver;

/**
 * Observer for the User model providing automatic audit logging.
 *
 * Inherits standard audit logging from AbstractObserver for created,
 * deleted, restored, and forceDeleted lifecycle events.
 *
 * Listens to: created, deleted, restored, forceDeleted (via AbstractObserver)
 * Side effects: AuditLogEvent dispatch
 *
 * @see \App\Models\User
 * @package Cms\Core\Observers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final class UserObserver extends AbstractObserver
{
    /**
     * {@inheritdoc}
     */
    protected function modelClass(): string
    {
        return User::class;
    }
}
