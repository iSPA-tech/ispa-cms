<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy for AuditLog model actions, using permission prefix 'auditLog'.
 * @package Cms\Core\Policies
 */
final class AuditLogPolicy extends AbstractModelPolicy
{
    use HandlesAuthorization;

    /**
     * Permission prefix for this policy.
     *
     * @return string
     */
    protected function prefix(): string
    {
        return 'audit_log';
    }
}
