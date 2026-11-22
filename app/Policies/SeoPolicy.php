<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Policies;

use App\Models\Seo;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SeoPolicy
{
    use HandlesAuthorization;

    /**
     * Allow local development access
     *
     * @param Closure $user
     *
     * @return bool|void
     *
     * @author malayvuong
     *
     * @since 1.0.0
     **/
    public function before($user, $ability)
    {
        if (app()->environment('local')) {
            return true;
        }
    }

    /**
     * Determine whether the user can view any models.
     *
     * @param User $user
     *
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('seo.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user
     * @param Seo $model
     *
     * @return bool
     */
    public function view(User $user, Seo $model): bool
    {
        return $user->hasRole('seo.view') or $user->id === $model->user_id;
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user
     *
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->hasRole('seo.create');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user
     * @param Seo $model
     *
     * @return bool
     */
    public function update(User $user, Seo $model): bool
    {
        return $user->hasRole('seo.update') or $user->id === $model->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user
     * @param Seo $model
     *
     * @return bool
     */
    public function delete(User $user, Seo $model): bool
    {
        return $user->hasRole('seo.delete') or $user->id === $model->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param User $user
     * @param Seo $model
     *
     * @return bool
     */
    public function restore(User $user, Seo $model): bool
    {
        return $user->hasRole('seo.restore') or $user->id === $model->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param User $user
     * @param Seo $model
     *
     * @return bool
     */
    public function forceDelete(User $user, Seo $model): bool
    {
        return $user->hasRole('seo.forceDelete') or $user->id === $model->user_id;
    }
}
