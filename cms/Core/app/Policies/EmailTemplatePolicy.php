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
 * Authorization policy for EmailTemplate management.
 *
 * Extends AbstractModelPolicy with permission prefix "email_template",
 * providing standard CRUD authorization (viewAny, view, create, update,
 * delete, restore, forceDelete).
 *
 * @see \Cms\Core\Policies\AbstractModelPolicy
 * @see \Cms\Core\Models\EmailTemplate
 * @package Cms\Core\Policies
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final class EmailTemplatePolicy extends AbstractModelPolicy
{
    use HandlesAuthorization;

    protected function prefix(): string
    {
        return 'email_template';
    }

    /**
     * Whether the user may make a template `system` mail (on create, or by changing its class). Workflows send system
     * mail with no approval and no switch, so only a Webmaster may set it.
     */
    public function setSystemClass(mixed $user): bool
    {
        return is_object($user) && method_exists($user, 'isWebmaster') && $user->isWebmaster();
    }
}
