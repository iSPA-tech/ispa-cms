<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Requests\ConfigPackage;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base form request of the configuration package admin API.
 *
 * Access is decided by the `admin.webmaster` route middleware. A validation failure answers 422 with errorCode
 * `VALIDATION_FAILED` and the flat list of messages in `data.errors` (messages name fields, never submitted values).
 */
abstract class ConfigPackageRequest extends FormRequest
{
    /**
     * Access is decided by the `admin.webmaster` route middleware.
     *
     * @return bool Always true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Answer 422 with the validation messages.
     *
     * @param Validator $validator Failed validator.
     *
     * @throws HttpResponseException Always.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ires()->error()->code(422)->errorCode('VALIDATION_FAILED')
                ->message('The request is not valid.')
                ->data(['errors' => array_values($validator->errors()->all())])
                ->toResponse($this)
        );
    }
}
