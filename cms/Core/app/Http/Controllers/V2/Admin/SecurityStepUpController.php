<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Admin\StepUpRequest;
use Cms\Core\Services\Security\StepUpConfirmation;
use Illuminate\Http\Request;

/**
 * Step-up confirmation of the signed-in operator before a token revoke: status, password, second
 * factor and e-mail code. Any signed-in operator confirms for their own account; the grant is bound to the token of
 * the request. Failures are rendered by {@see \Cms\Core\Services\Security\StepUpException} (422, 429 with Retry-After,
 * 503). Responses never carry a password, a code or a hash; the grant appears only in the response that issues it.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 */
class SecurityStepUpController extends AdminController
{
    /**
     * @param StepUpConfirmation $confirmation Step-up service.
     */
    public function __construct(private readonly StepUpConfirmation $confirmation)
    {
        parent::__construct();
    }

    /**
     * What the account needs to confirm and whether it is locked.
     *
     * @param Request $request Signed-in request.
     * @return mixed Admin API response.
     */
    public function status(Request $request): mixed
    {
        $user = $request->user();

        return ires()->data($this->confirmation->status($user, $user->currentAccessToken()));
    }

    /**
     * Check the password; returns the grant or the second-factor step.
     *
     * @param StepUpRequest $request Body: password, purpose.
     * @return mixed Admin API response.
     */
    public function start(StepUpRequest $request): mixed
    {
        $user = $request->user();

        return ires()->data($this->confirmation->start($user, $user->currentAccessToken(), $request->validated('password'), $request->purpose()));
    }

    /**
     * Check the code of the current second-factor step; returns the grant or the next step.
     *
     * @param StepUpRequest $request Body: challenge_id, code, method.
     * @return mixed Admin API response.
     */
    public function secondFactor(StepUpRequest $request): mixed
    {
        $user = $request->user();

        return ires()->data($this->confirmation->verifySecondFactor(
            $user,
            $user->currentAccessToken(),
            (string) $request->validated('challenge_id'),
            (string) $request->validated('code'),
            $request->validated('method'),
        ));
    }

    /**
     * Send or resend an e-mail code (or start the confirmation of an account without a password).
     *
     * @param StepUpRequest $request Body: challenge_id, purpose.
     * @return mixed Admin API response.
     */
    public function emailCode(StepUpRequest $request): mixed
    {
        $user = $request->user();

        return ires()->data($this->confirmation->sendEmailCode($user, $user->currentAccessToken(), $request->validated('challenge_id'), $request->purpose()));
    }
}
