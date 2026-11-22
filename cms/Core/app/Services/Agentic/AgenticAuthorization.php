<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Agentic;

use App\Models\User;

/**
 * Authorization gate for agentic subsystem operations.
 *
 * Checks user permissions for viewing, provisioning, approving,
 * and operating agentic resources. Webmasters bypass all checks.
 * Checks the live permissions of the account (hasPermissionResolved, then
 * the Gate through can). The abilities snapshot stored on an API token is
 * never consulted, so a permission removed from the account stops working
 * for its existing tokens on the very next request.
 *
 * @package Cms\Core\Services\Agentic
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AgenticAuthorization
{
    /**
     * Check if the user has any of the given permission keys.
     *
     * @param User|null          $user The user to check.
     * @param array<int, string> $keys Permission keys to check against.
     * @return bool True if the user has at least one of the specified permissions.
     */
    public function allows(?User $user, array $keys): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isWebmaster()) {
            return true;
        }

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (method_exists($user, 'hasPermissionResolved') && $user->hasPermissionResolved($key)) {
                return true;
            }

            if (method_exists($user, 'can') && $user->can($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user can view agentic resources.
     *
     * @param User|null $user The user to check.
     * @return bool
     */
    public function canView(?User $user): bool
    {
        return $this->allows($user, [
            'agentic.viewAny',
            'workflow.viewAny',
            'workflow_project.viewAny',
        ]);
    }

    /**
     * Check if the user can provision agentic profiles and tools.
     *
     * @param User|null $user The user to check.
     * @return bool
     */
    public function canProvision(?User $user): bool
    {
        return $this->allows($user, [
            'agentic.update',
            'workflow.update',
            'workflow_project.update',
        ]);
    }

    /**
     * Check if the user can approve or reject agent artifacts.
     *
     * @param User|null $user The user to check.
     * @return bool
     */
    public function canApprove(?User $user): bool
    {
        return $this->allows($user, [
            'agentic.approve',
            'agentic.update',
        ]);
    }

    /**
     * Check if the user can perform operational actions (stop, cancel, rerun).
     *
     * @param User|null $user The user to check.
     * @return bool
     */
    public function canOperate(?User $user): bool
    {
        return $this->allows($user, [
            'agentic.update',
            'workflow.update',
        ]);
    }
}
