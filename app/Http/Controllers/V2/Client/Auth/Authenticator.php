<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Client\Auth;

use Exception;
use App\Constants\UserType;
use App\Models\User;
use App\Constants\CacheConstants;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Requests\Auth\SignInRequest;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\UserInterface;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\TokenAudience;

class Authenticator extends AdminController
{
    /**
     * Authenticator constructor
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-10-16, 09:19 ICT
     */
    public function __construct(
        protected UserInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Process to sign in
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-15, 11:36
     */
    public function signIn(SignInRequest $request)
    {
        try {
            $user = $this->model->getByEmailOrUsername($request->input('username'), [
                'roles' => function ($q) {
                    $q->with('permissions');
                },
            ]);

            if (!$user || !Hash::check($request->input('password'), $user->password)) {
                /** Audit log sign in */
                if ($user) {
                    event(new AuditLogEvent('client.sign-in.failed', [], User::class, $user->id, 'fa fa-sign-in', 'danger'));
                }

                return ires()->failed()->message(__('mgs.auth.failed'));
            }

            /**
             * A staff account never gets a client token: the admin API accepts a bearer token by account type alone, so this
             * password-only route would hand a Webmaster or Admin account an admin-capable token with no admin sign-in and no
             * second factor. Staff sign in through the admin sign-in.
             */
            if (in_array((int) $user->type, [UserType::WEBMASTER, UserType::ADMIN], true)) {
                event(new AuditLogEvent('client.sign-in.refused', ['reason' => 'staff_account'], User::class, $user->id, 'fa fa-sign-in', 'danger'));

                return ires()->failed()->message(__('mgs.no.permission'));
            }

            /** Todo: check user status / banned */
            /** Issued through the issuer: audience `client`, device record and the lifetime from the token policy. */
            $tokenResult = app(ApiTokenIssuer::class)->issue(
                $user,
                TokenAudience::CLIENT,
                'authToken',
                $user->permissions(),
                $request,
                null,
                'client.sign-in'
            )->plainTextToken;

            /** Audit log sign in */
            event(new AuditLogEvent('client.sign-in.success', [], User::class, $user->id, 'fa fa-sign-in', 'success'));

            //  Store redis tempporary database
            Redis::set(
                'users:' . ($user->uuid ?? $user->id),
                json_encode($user, JSON_THROW_ON_ERROR),
                'EX',
                CacheConstants::CLIENT_USER_SNAPSHOT_TTL
            );
        } catch (Exception $e) {
            return ires()->error()->message($e->getMessage());
        }

        return ires()->data([
            'token' => $tokenResult,
            'user' => $user,
        ])->message(__('mgs.ok.sign-in'));
    }
}
