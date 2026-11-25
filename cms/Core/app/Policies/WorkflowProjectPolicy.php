<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Policies;

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowProject;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for WorkflowProject resources.
 *
 * Controls access to workflow project CRUD operations. Every environment, local included, requires the
 * corresponding role permission (e.g., workflow_project.viewAny) or must own the project
 * (matching users_id) for view/update/delete/restore/forceDelete actions. publish() and run() have no owner shortcut.
 *
 * @see \Cms\Core\Models\Workflows\WorkflowProject
 * @package Cms\Core\Policies
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowProjectPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param User $user
     *
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('workflow_project.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user
     * @param WorkflowProject $model
     *
     * @return bool
     */
    public function view(User $user, WorkflowProject $model): bool
    {
        return $user->hasRole('workflow_project.view') or $user->id === $model->users_id;
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
        return $user->hasRole('workflow_project.create');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user
     * @param WorkflowProject $model
     *
     * @return bool
     */
    public function update(User $user, WorkflowProject $model): bool
    {
        return $user->hasRole('workflow_project.update') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user
     * @param WorkflowProject $model
     *
     * @return bool
     */
    public function delete(User $user, WorkflowProject $model): bool
    {
        return $user->hasRole('workflow_project.delete') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param User $user
     * @param WorkflowProject $model
     *
     * @return bool
     */
    public function restore(User $user, WorkflowProject $model): bool
    {
        return $user->hasRole('workflow_project.restore') or $user->id === $model->users_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param User $user
     * @param WorkflowProject $model
     *
     * @return bool
     */
    public function forceDelete(User $user, WorkflowProject $model): bool
    {
        return $user->hasRole('workflow_project.forceDelete') or $user->id === $model->users_id;
    }

    /**
     * Whether the user may publish (or roll back) a version of the workflow, i.e. change what runs on the live site
     * (roadmap B8). Permission `workflow_project.publish` or Webmaster only: owning the project is not enough, so an
     * editor who drafts a workflow cannot release it.
     *
     * @param User $user
     * @param WorkflowProject $model
     *
     * @return bool
     */
    public function publish(User $user, WorkflowProject $model): bool
    {
        return $user->isWebmaster() || $user->hasRole('workflow_project.publish');
    }

    /**
     * Whether the user may start a run of the workflow by hand (roadmap B8). Permission `workflow_project.run` or
     * Webmaster only; no owner shortcut.
     *
     * @param User $user
     * @param WorkflowProject $model
     *
     * @return bool
     */
    public function run(User $user, WorkflowProject $model): bool
    {
        return $user->isWebmaster() || $user->hasRole('workflow_project.run');
    }
}
