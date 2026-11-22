<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Requests\Admin;

use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\StepUpConfirmation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of the step-up confirmation routes, shared by the admin and the Account routes. Every field is
 * optional here; which one a step needs depends on the account and is checked by {@see StepUpConfirmation}. Validation
 * errors name the field and never echo its value.
 *
 * @package Cms\Core\Http\Requests\Admin
 */
class StepUpRequest extends FormRequest
{
    /**
     * Any signed-in account may confirm for itself; the route middleware has authenticated it.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // The second-factor step (route names `*.step-up.second-factor`) needs the confirmation id and the code.
        $secondFactor = $this->routeIs('*step-up.second-factor');

        return [
            'password' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'purpose' => ['sometimes', 'string', 'in:' . implode(',', StepUpConfirmation::PURPOSES)],
            'challenge_id' => [$secondFactor ? 'required' : 'sometimes', 'nullable', 'string', 'uuid'],
            'code' => [$secondFactor ? 'required' : 'sometimes', 'string', 'max:64'],
            'method' => ['sometimes', 'nullable', 'string', 'in:' . AdminMfaService::METHOD_RECOVERY_CODE],
        ];
    }

    /**
     * Validated grant purpose (default `revoke_tokens`).
     *
     * @return string
     */
    public function purpose(): string
    {
        return (string) ($this->validated('purpose') ?? StepUpConfirmation::PURPOSE_REVOKE_TOKENS);
    }
}
