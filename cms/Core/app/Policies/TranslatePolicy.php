<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Policies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

/**
 * Policy for Translate model actions, using permission prefix 'translate'.
 * @package Cms\Core\Policies
 */
final class TranslatePolicy extends AbstractModelPolicy
{
    use HandlesAuthorization;

    /**
     * Permission prefix for this policy.
     *
     * @return string
     */
    protected function prefix(): string
    {
        return 'translate';
    }

    /**
     * Generate file transcript
     *
     * @param  UserContract $user
     * @param  Model $model
     * @return bool
     * @author malayvuong
     * @since 7.160.1175 - 2025-10-11, 15:46 ICT
     */
    public function generate(UserContract $user, Model $model): bool
    {
        return $this->checkPermission($user, $this->perm('generate'));
    }
}
