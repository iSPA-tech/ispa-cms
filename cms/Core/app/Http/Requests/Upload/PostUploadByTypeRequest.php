<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Upload;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Form request for the typed file upload endpoint.
 *
 * Resolves the upload type from the route parameter {type} or falls back to the
 * request body parameter 'type'. Determines the maximum file size from configuration
 * (setting.upload.max_file_size.{type}) and builds dynamic MIME type constraints
 * per type: image/avatar (jpeg, jpg, png, gif, webp, avif), video (mp4, mov, avi, wmv),
 * audio (mp4, mp3, m4a, wma), archive (zip, rar, 7z), document (pdf, doc, docx, xls,
 * xlsx, ppt, pptx), and 'page' which is the union of all types.
 *
 * @package Cms\Core\Http\Requests\Upload
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostUploadByTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        // Prefer route param `{type}` when available, else use body param
        $routeType = method_exists($this, 'route') ? ($this->route('type') ?? null) : null;
        $type = str($routeType ?: request('type', 'all'))->lower()->toString();
        //  Get max file size by type
        $maxFileSize = $this->maxFileSizeInKilobytes((int) config('setting.upload.max_file_size.' . $type, 1024 * 1024 * 12));

        $mime_types = [
            'image' => '|mimes:jpeg,jpg,png,gif,webp,avif',
            'avatar' => '|mimes:jpeg,jpg,png,gif,webp,avif',
            'video' => '|mimes:mp4,mov,avi,wmv',
            'audio' => '|mimes:mp4,mp3,m4a,wma',
            'archive' => '|mimes:zip,rar,7z',
            'document' => '|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 1,
        ];
        // For `page` context, allow the union of common file types
        if ($type === 'page') {
            $mimes = $mime_types['image'] . ',' .
                str_replace('|mimes:', '', $mime_types['document']) . ',' .
                str_replace('|mimes:', '', $mime_types['archive']) . ',' .
                str_replace('|mimes:', '', $mime_types['video']) . ',' .
                str_replace('|mimes:', '', $mime_types['audio']);
            $mimes = '|mimes:' . trim(str_replace(['|mimes:', '||'], ['', '|'], $mimes), ',');
        } else {
            $mimes = $mime_types[$type] ?? '';
        }

        return [
            'type' => 'string|nullable|in:image,avatar,video,audio,archive,document,all,page',
            'file' => 'required|file|max:' . $maxFileSize . $mimes,
        ];
    }

    private function maxFileSizeInKilobytes(int $configuredSize): int
    {
        if ($configuredSize < 1) {
            return 12 * 1024;
        }

        if ($configuredSize > 1024 * 1024) {
            return (int) ceil($configuredSize / 1024);
        }

        return $configuredSize;
    }
}
