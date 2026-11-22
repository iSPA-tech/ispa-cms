<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Rules;

use Countable;
use Illuminate\Contracts\Validation\Rule;

class ContentRequired implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param    $attribute
     * @param mixed $value
     *
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        if ((is_countable($value) || $value instanceof Countable) && count($value) < 1) {
            return false;
        }

        if (is_array($value)) {
            //  Check if all type=text value is empty, return false
            $collectValue = collect($value);
            // A complete chart is article content even without a separate text block.
            $hasStructuredContent = $collectValue->contains(function ($item) {
                return is_array($item) && in_array($item['type'] ?? null, ['widget', 'data_chart'], true);
            });
            if ($hasStructuredContent) {
                return true;
            }

            $textValues = $collectValue
                ->filter(fn ($item) => is_array($item) && ($item['type'] ?? null) === 'text')
                ->all();
            //  Count text value has content
            $textValuesCount = collect($textValues)->filter(function ($item) {
                return !empty($item['value'] ?? null);
            })->count();

            if ($textValuesCount < 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return trans('validation.required', [
            'attribute' => __('validation.attributes.content'),
        ]);
    }
}
