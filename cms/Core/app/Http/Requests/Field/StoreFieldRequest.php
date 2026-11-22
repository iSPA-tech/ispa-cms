<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Field;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for creating a new Field resource.
 *
 * Validates: name (required, max 255), form_id (optional, max 32),
 * status (required, must exist in enumerates.enum_key), and items array
 * (required, minimum 1 item) where each item must have name (max 255),
 * field_name (max 50), type (max 32), and optional description (max 500).
 *
 * @package Cms\Core\Http\Requests\Field
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreFieldRequest extends FormRequest
{
    use RedirectFailedTrait;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'form_id' => 'nullable|string|max:32',
            'status' => 'required|exists:enumerates,enum_key',
            'custom' => 'nullable|array',
            'custom.layout' => 'nullable|array',
            'custom.layout.columns' => 'nullable|array|max:10',
            'custom.layout.columns.mobile' => 'nullable|integer|between:1,3',
            'custom.layout.columns.tablet' => 'nullable|integer|between:1,3',
            'custom.layout.columns.desktop' => 'nullable|integer|between:1,3',
            'items' => 'required|array|min:1|max:100',
            'items.*.id' => 'nullable|integer|min:0',
            'items.*.name' => 'string|required|max:255',
            'items.*.field_name' => 'string|required|max:50',
            'items.*.type' => 'string|required|max:32',
            'items.*.description' => 'string|nullable|max:500',
            'items.*.options' => 'nullable|array|max:100',
            'items.*.custom' => 'nullable|array',
            'items.*.custom.layout' => 'nullable|array',
            'items.*.custom.layout.span' => 'nullable|array|max:10',
            'items.*.custom.layout.span.mobile' => 'nullable|in:auto,full,1,2,3',
            'items.*.custom.layout.span.tablet' => 'nullable|in:auto,full,1,2,3',
            'items.*.custom.layout.span.desktop' => 'nullable|in:auto,full,1,2,3',
            'items.*.custom.required' => 'nullable|boolean',
            'items.*.custom.optionsSource' => 'nullable|array:source,filters,dependsOn',
            'items.*.custom.optionsSource.source' => 'nullable|string|max:100',
            'items.*.custom.optionsSource.filters' => 'nullable|array|max:20',
            'items.*.custom.optionsSource.dependsOn' => 'nullable|array|max:20',
            'items.*.custom.optionsSource.dependsOn.*' => 'nullable|string|max:50',
            'items.*.custom.placeholder' => 'nullable|string|max:255',
            'items.*.custom.section' => 'nullable|string|max:255',
            'items.*.custom.choiceLayout' => 'nullable|in:auto,inline,stacked',
            'items.*.custom.maxFileSizeMb' => 'nullable|integer|between:1,10',
            'items.*.custom.visibleWhen' => 'nullable|array',
            'items.*.custom.visibleWhen.field' => 'nullable|string|max:50',
            'items.*.custom.visibleWhen.value' => 'nullable|string|max:255',
            'items.*.custom.allowFileType' => 'nullable|string|max:255',
            'items.*.custom.aspectRatio' => ['nullable', 'regex:/^[1-9][0-9]?\/[1-9][0-9]?$/'],
        ];
    }

    /**
     * Custom message
     *
     * @return array
     * @author malayvuong
     *
     * @since 1.0.0 - 12/5/21 16:27
     */
    public function messages(): array
    {
        return [
            'items.required' => __('validation.required', ['attribute' => __('adm.t.formItems')]),
            'items.min' => __('validation.min.array', ['attribute' => __('adm.t.formItems')]),
            'items.array' => __('validation.array', ['attribute' => __('adm.t.formItems')]),
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'tên biểu mẫu',
            'form_id' => 'biểu mẫu',
            'custom.layout.columns.mobile' => 'số cột trên điện thoại',
            'custom.layout.columns.tablet' => 'số cột trên máy tính bảng',
            'custom.layout.columns.desktop' => 'số cột trên máy tính',
            'items' => 'các mục thông tin',
            'items.*.name' => 'tên mục',
            'items.*.type' => 'loại nội dung',
            'items.*.field_name' => 'mục thông tin',
            'items.*.description' => 'ghi chú hướng dẫn',
            'items.*.custom.required' => 'bắt buộc điền',
            'items.*.custom.layout.span.mobile' => 'độ rộng mục trên điện thoại',
            'items.*.custom.layout.span.tablet' => 'độ rộng mục trên máy tính bảng',
            'items.*.custom.layout.span.desktop' => 'độ rộng mục trên máy tính',
            'items.*.custom.optionsSource' => 'nguồn danh sách',
            'items.*.custom.visibleWhen.field' => 'mục liên quan',
            'items.*.custom.visibleWhen.value' => 'lựa chọn liên quan',
            'items.*.custom.allowFileType' => 'định dạng tệp',
            'items.*.custom.aspectRatio' => 'tỷ lệ ảnh',
            'items.*.custom.placeholder' => 'gợi ý nhập liệu',
            'items.*.custom.section' => 'tiêu đề nhóm',
            'items.*.custom.choiceLayout' => 'cách xếp lựa chọn',
            'items.*.custom.maxFileSizeMb' => 'dung lượng tệp tối đa (MB)',
        ];
    }
}
