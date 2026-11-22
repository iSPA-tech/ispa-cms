<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http;

use App\Enums\ErrorCodeEnum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trait for overriding Laravel FormRequest validation failure behaviour.
 *
 * Instead of the default redirect-based error response, this trait
 * throws a ValidationException with a structured JSON payload containing
 * a flat array of error messages and the application error code. Intended
 * for API-oriented FormRequest classes where redirect responses are
 * inappropriate.
 *
 * @see \App\Enums\ErrorCodeEnum
 * @package Cms\Core\Http
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait RedirectFailedTrait
{
    /**
     * Check for validator fail flag.
     *
     * @return bool
     */
    public function validatorFails(): bool
    {
        return $this->getValidatorInstance()->fails();
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     *
     * @return void
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, response()->json([
            'status' => 'error',
            'message' => $this->validatorErrors(),
            'error_code' => ErrorCodeEnum::get('VALIDATION_FAILED'),
        ], Response::HTTP_NOT_ACCEPTABLE));
    }

    /**
     * Flatten all validation error messages into a single array.
     *
     * Iterates over the validator's error bag and collects every
     * individual message string, discarding the field-name grouping.
     *
     * @return array  Flat list of validation error message strings
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function validatorErrors(): array
    {
        $errors = [];

        foreach ($this->getValidatorInstance()->errors()->toArray() as $value) {
            foreach ($value as $message) {
                $errors[] = $message;
            }
        }

        return $errors;
    }
}
