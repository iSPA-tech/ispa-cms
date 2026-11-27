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
 * Abstract base policy providing standard CRUD authorization for Eloquent models.
 *
 * Implements a consistent authorization pattern across all CMS model policies:
 * - Local environment bypass for development
 * - Conventional permission string: "{prefix}.{ability}" (e.g., "permission.create")
 * - Safe ownership check on models with a configurable owner key (default: user_id)
 * - Compatible with different user permission APIs: hasPermission(), hasRole(), can()
 *
 * Concrete policies must implement prefix() and may override ownershipKey() when the
 * model stores owner identity in a column other than user_id.
 *
 * Usage example:
 *   final class PermissionPolicy extends AbstractModelPolicy
 *   {
 *       protected function prefix(): string { return 'permission'; }
 *   }
 *
 * @package Cms\Core\Policies
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
abstract class AbstractModelPolicy
{
    use HandlesAuthorization;

    /**
     * Local environment bypasses all policy checks. Webmaster bypass is limited
     * to role and permission management policies.
     * Override to change the condition.
     * @param  UserContract $user
     * @param  string $ability
     * @return bool|null
     */
    public function before(UserContract $user, string $ability): ?bool
    {
        if ($this->isSuperUser($user)) {
            return true;
        }

        return null;
    }

    /**
     * True if the current environment/user should bypass policy checks.
     */
    protected function isSuperUser(UserContract $user): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return method_exists($user, 'isWebmaster')
            && $user->isWebmaster()
            && in_array($this->prefix(), ['permission', 'role'], true);
    }

    /**
     * Determine whether the user can view any models.
     * @param  UserContract $user
     * @return bool
     */
    public function viewAny(UserContract $user): bool
    {
        return $this->checkPermission($user, $this->perm('viewAny'));
    }

    /**
     * Try multiple permission APIs to support different user implementations.
     */
    protected function checkPermission(UserContract $user, string $permission): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return (bool)$user->hasPermission($permission);
        }
        if (method_exists($user, 'hasRole')) {
            // Some codebases store permissions in roles; keep compatibility.
            return (bool)$user->hasRole($permission);
        }
        if (method_exists($user, 'can')) {
            // Fallback to Gate ability
            return (bool)$user->can($permission);
        }

        return false;
    }

    /**
     * Build full permission string from the prefix: "<prefix>.<action>"
     */
    protected function perm(string $action): string
    {
        return $this->prefix() . '.' . $action;
    }

    /**
     * The permission key this policy checks for an action (e.g. `email.viewAny`), for screens that suggest who sees
     * a kind of record (workflow setup "who is told").
     */
    public function permissionFor(string $action): string
    {
        return $this->perm($action);
    }

    /**
     * Return the permission prefix for this policy.
     * Example for a PermissionPolicy: "permission"
     */
    abstract protected function prefix(): string;

    /**
     * Determine whether the user can view the model.
     * @param  UserContract $user
     * @param  Model $model
     * @return bool
     */
    public function view(UserContract $user, Model $model): bool
    {
        return $this->checkPermission($user, $this->perm('view'))
            || $this->owns($user, $model);
    }

    /**
     * Safe ownership check based on a configurable key (default: "user_id").
     */
    protected function owns(UserContract $user, Model $model): bool
    {
        $key = $this->ownershipKey();

        return isset($model->{$key}) && (string)$model->{$key} === (string)$user->getAuthIdentifier();
    }

    /**
     * The model attribute that stores the owner's user id.
     * Override per model if different (e.g., "owner_id").
     */
    protected function ownershipKey(): string
    {
        return 'user_id';
    }

    /**
     * Determine whether the user can create models.
     * @param  UserContract $user
     * @return bool
     */
    public function create(UserContract $user): bool
    {
        return $this->checkPermission($user, $this->perm('create'));
    }

    /* ----------------------------- Helpers ----------------------------- */

    /**
     * Determine whether the user can update the model.
     * @param  UserContract $user
     * @param  Model $model
     * @return bool
     */
    public function update(UserContract $user, Model $model): bool
    {
        return $this->checkPermission($user, $this->perm('update'))
            || $this->owns($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     * @param  UserContract $user
     * @param  Model $model
     * @return bool
     */
    public function delete(UserContract $user, Model $model): bool
    {
        return $this->checkPermission($user, $this->perm('delete'))
            || $this->owns($user, $model);
    }

    /**
     * Determine whether the user can restore the model.
     * @param  UserContract $user
     * @param  Model $model
     * @return bool
     */
    public function restore(UserContract $user, Model $model): bool
    {
        return $this->checkPermission($user, $this->perm('restore'))
            || $this->owns($user, $model);
    }

    /**
     * Determine whether the user can permanently delete the model.
     * @param  UserContract $user
     * @param  Model $model
     * @return bool
     */
    public function forceDelete(UserContract $user, Model $model): bool
    {
        return $this->checkPermission($user, $this->perm('forceDelete'))
            || $this->owns($user, $model);
    }
}
