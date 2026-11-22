<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\StepUpException;
use Illuminate\Http\Request;

/**
 * Route middleware `security.stepup:<purpose>[,consume]`: the request must carry a valid step-up grant in the
 * `X-Step-Up-Grant` header, issued to the signed-in user, for the token of this request and for this purpose. Without
 * one the route answers 403 `step_up_required` and does nothing. With `consume` the grant is used up before the action
 * runs (bulk revokes); without it the grant may be reused until it expires (single-token revokes). A failing cache
 * answers 503 `step_up_unavailable`. Place it after `auth:sanctum`.
 *
 * @package Cms\Core\Http\Middleware
 */
final class RequireStepUpGrant
{
    /**
     * @param StepUpConfirmation $confirmation Grant store.
     */
    public function __construct(private readonly StepUpConfirmation $confirmation)
    {
    }

    /**
     * Check (and with `consume` use up) the grant.
     *
     * @param Request $request Incoming request.
     * @param Closure $next Next handler.
     * @param string $purpose Grant purpose the route needs.
     * @param string|null $mode `consume` to use the grant up.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $purpose = StepUpConfirmation::PURPOSE_REVOKE_TOKENS, ?string $mode = null): mixed
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return StepUpException::required()->render();
        }

        try {
            $valid = $this->confirmation->checkGrant(
                $user,
                $user->currentAccessToken(),
                $request->header(StepUpConfirmation::HEADER),
                $purpose,
                $mode === 'consume',
            );
        } catch (StepUpException $e) {
            return $e->render();
        }

        if (! $valid) {
            return StepUpException::required()->render();
        }

        return $next($request);
    }
}
