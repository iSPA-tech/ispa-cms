<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Cms\Core\Services\Import\ImportOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Form request for the generic CMS data import endpoint.
 *
 * Validates the uploaded import file (max 50 MB; must be csv, txt, xls, or xlsx) and the
 * optional import behaviour flags: dry_run, transaction_mode (single|chunk|none),
 * chunk_size, limit_preview_rows, stop_on_error, always_update, and strict_headers.
 * Authorization is delegated to controller-level token checks. Failed validation returns
 * a consistent error response via the ires() helper when available.
 *
 * @see \Cms\Core\Services\Import\ImportOptions
 * @package Cms\Core\Http\Requests
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CoreImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * @return bool
     */
    public function authorize(): bool
    {
        // Authorization is handled at controller level via tokenCan()
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     * @return array
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|max:51200', // 50MB max
            'dry_run' => 'boolean',
            'transaction_mode' => 'nullable|string|in:single,chunk,none',
            'chunk_size' => 'nullable|integer|min:1',
            'limit_preview_rows' => 'nullable|integer|min:1',
            'stop_on_error' => 'boolean',
            'always_update' => 'boolean',
            'strict_headers' => 'boolean',
        ];
    }

    /**
     * Configure the validator instance.
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->hasFile('file')) {
                $file = $this->file('file');
                $extension = strtolower($file->getClientOriginalExtension());
                $allowedExtensions = ['csv', 'txt', 'xls', 'xlsx'];

                if (!in_array($extension, $allowedExtensions)) {
                    $validator->errors()->add(
                        'file',
                        'The file must be one of the following types: csv, txt, xls, xlsx.'
                    );
                }
            }
        });
    }

    /**
     * Build an ImportOptions value object from the validated request data.
     *
     * @return ImportOptions Configured import options for the importer service
     */
    public function toImportOptions(): ImportOptions
    {
        return new ImportOptions(
            transaction_mode: $this->input('transaction_mode', 'single'),
            dry_run: $this->boolean('dry_run', false),
            chunk_size: $this->integer('chunk_size', 500),
            limit_preview_rows: $this->input('limit_preview_rows'),
            stop_on_error: $this->boolean('stop_on_error', false),
            always_update: $this->boolean('always_update', false),
            strict_headers: $this->boolean('strict_headers', false),
        );
    }

    /**
     * Handle a failed validation attempt.
     * Override to return consistent error format with ires() helper.
     *
     * @throws ValidationException When validation fails.
     */
    protected function failedValidation(Validator $validator): void
    {
        // Check if ires() helper exists and return consistent format
        if (function_exists('ires')) {
            throw new ValidationException(
                $validator,
                ires()->error()->message('Validation failed')->data([
                    'errors' => $validator->errors()->toArray(),
                ])->toResponse(request())
            );
        }

        // Fall back to default Laravel behavior
        parent::failedValidation($validator);
    }
}
