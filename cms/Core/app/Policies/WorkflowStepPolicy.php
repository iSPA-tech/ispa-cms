<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Policies;

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowStep;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for WorkflowStep resources.
 *
 * Controls access to workflow step CRUD operations. Local environment bypasses
 * checks via before(). All other users require the
 * corresponding role permission (e.g., workflow_step.viewAny) or must own the step
 * (matching users_id) for view/update/delete/restore/forceDelete actions.
 *
 * @see \Cms\Core\Models\Workflows\WorkflowStep
 * @package Cms\Core\Policies
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowStepPolicy
{
    use HandlesAuthorization;

    /**
     * Allow local development access.
     *
     * @param Closure $user
     * @param  mixed $ability
     *
     * @return bool|void
     *
     * @author malayvuong
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
        return $user->hasRole('workflow_step.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user
     * @param WorkflowStep $model
     *
     * @return bool
     */
    public function view(User $user, WorkflowStep $model): bool
    {
        return $user->hasRole('workflow_step.view') or $user->id === $model->users_id;
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
        return $user->hasRole('workflow_step.create');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user
     * @param WorkflowStep $model
     *
     * @return bool
     */
    public function update(User $user, WorkflowStep $model): bool
    {
        return $user->hasRole('workflow_step.update') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user
     * @param WorkflowStep $model
     *
     * @return bool
     */
    public function delete(User $user, WorkflowStep $model): bool
    {
        return $user->hasRole('workflow_step.delete') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param User $user
     * @param WorkflowStep $model
     *
     * @return bool
     */
    public function restore(User $user, WorkflowStep $model): bool
    {
        return $user->hasRole('workflow_step.restore') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param User $user
     * @param WorkflowStep $model
     *
     * @return bool
     */
    public function forceDelete(User $user, WorkflowStep $model): bool
    {
        return $user->hasRole('workflow_step.forceDelete') or $user->id === $model->users_id;
    }
}
