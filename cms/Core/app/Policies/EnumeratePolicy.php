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
 * Policy for Enumerate model actions, using permission prefix 'enumerate'.
 * @package Cms\Core\Policies
 */
final class EnumeratePolicy extends AbstractModelPolicy
{
    use HandlesAuthorization;

    /**
     * Permission prefix for this policy.
     *
     * @return string
     */
    protected function prefix(): string
    {
        return 'enumerate';
    }
}
