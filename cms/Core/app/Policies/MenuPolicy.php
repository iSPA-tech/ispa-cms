<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Policies;

use Cms\Core\Policies\AbstractModelPolicy;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for Menu management.
 *
 * Extends AbstractModelPolicy with permission prefix "menu",
 * providing standard CRUD authorization (viewAny, view, create, update,
 * delete, restore, forceDelete).
 *
 * @see \Cms\Core\Policies\AbstractModelPolicy
 * @see \Cms\Core\Models\Menu
 * @package Cms\Core\Policies
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final class MenuPolicy extends AbstractModelPolicy
{
    use HandlesAuthorization;

    protected function prefix(): string
    {
        return 'menu';
    }
}
