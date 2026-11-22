<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Cms\Core\Interfaces\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Class UserRepository
 * Eloquent repository facade for the core User model.
 *
 * @extends RepositoriesAbstract
 * @implements UserInterface
 * @see User
 *
 * @method Model|Collection|Builder|array|null find(int $id, array $select = ['*'], array $with = [])
 * @method mixed get(array $params)
 * @package Cms\Core\Repositories
 */
class UserRepository extends RepositoriesAbstract implements UserInterface
{
    /**
     * Apply filters for user listing, including banned users in the default active view.
     * @param  mixed $model
     * @param  array $data
     * @return mixed
     */
    public function filter(mixed $model, array $data = []): mixed
    {
        if (($data['status'] ?? null) === UserStatusEnum::USER_ACTIVE->value) {
            $model = $model->whereIn('status', [UserStatusEnum::USER_ACTIVE->value, UserStatusEnum::USER_BANNED->value]);
            unset($data['status']);
        }

        return parent::filter($model, $data);
    }

    /**
     * Initialize the repository and bind the User model.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(User::class);
    }

    /**
     * Get User by UUID
     *
     * @param string $uuid
     * @param array $select
     *
     * @return Model|null
     * @author vuongnm
     * @since 6.62.6 - 2023-01-09, 10:49 ICT
     */
    public function findByUuid(string $uuid, array $select = ['*']): ?Model
    {
        return $this->findBy(['uuid' => $uuid], $select, ['settings']);
    }

    /**
     * Get user info by email OR username
     *
     * @param string $emailOrUsername
     * @param array $with
     *
     * @return Model|Collection|Builder|array|null
     * @author malayvuong
     * @since 6.19.6 - 2022-06-16, 23:38 ICT
     */
    public function getByEmailOrUsername(string $emailOrUsername = '', array $with = []): Model|Collection|Builder|array|null
    {
        // Login accepts either username or email. An input with "@" is looked up as an email first, by its shape only:
        // a DNS check of the domain (checkdnsrr, removed 2026-11-19) hung sign-in on a slow resolver and sent emails on
        // domains without MX records (intranet, .test) to the username column, where they were never found. A miss
        // still tries the username column, for legacy usernames that contain "@".
        if (str_contains($emailOrUsername, '@')) {
            $user = $this->findBy(['email' => $emailOrUsername], ['*'], $with);
            if ($user !== null) {
                return $user;
            }
        }

        return $this->findBy(['username' => $emailOrUsername], ['*'], $with);
    }

    /**
     * Process to sign in
     *
     * @param  UserInterface $user
     * @return mixed
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-16, 23:37 ICT
     */
    public function signIn(UserInterface $user)
    {
    }
}
