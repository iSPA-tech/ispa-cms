<?php

declare(strict_types=1);

namespace Cms\Skeleton\Http\Requests\V2\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) needs a name; update (PUT/PATCH) changes only the fields sent. */
final class SkeletonThingRequest extends FormRequest
{
    /** The controller checks the policy. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
        ];
    }
}
