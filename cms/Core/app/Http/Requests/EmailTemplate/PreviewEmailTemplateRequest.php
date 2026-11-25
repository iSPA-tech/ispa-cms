<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests\EmailTemplate;

use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Services\Mail\MailLayoutAllowlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input of an e-mail template preview (`POST email-template/preview`, `POST email-template/{id}/preview`): optional
 * unsaved `name` (subject), `content`, `group`, `blade_layout` (allowlisted views only) and `sample_data`.
 * Authorization is checked by the controller (EmailTemplatePolicy: `view` on a saved row, else `create`).
 *
 * @package Cms\Core\Http\Requests\EmailTemplate
 * @since   2026-11-25
 */
class PreviewEmailTemplateRequest extends FormRequest
{
    use RedirectFailedTrait;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'content' => 'nullable|string|max:200000',
            'group' => 'nullable|string|max:255',
            'blade_layout' => ['nullable', 'string', 'max:255', Rule::in(MailLayoutAllowlist::layouts())],
            'sample_data' => 'nullable|array',
        ];
    }
}
