<?php

namespace Cms\Core\Rules;

use Closure;
use Cms\Core\Support\MenuCategoryExpansion;
use Illuminate\Contracts\Validation\ValidationRule;

final class MenuCategoryExpansionRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! MenuCategoryExpansion::itemTreeHasValidLevels($value)) {
            $fail(__('validation.integer', [
                'attribute' => __('text.menu-expand-child-level'),
            ]));
        }
    }
}
