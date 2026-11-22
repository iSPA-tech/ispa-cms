<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Resources\Content;

use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Resource collection for all content entries (unfiltered).
 *
 * Wraps a paginated or full collection of Content models using the default
 * Laravel resource collection serialization (delegates to parent::toArray).
 *
 * Output: default ResourceCollection format (data array with model attributes)
 * Used by: Admin content listing endpoints
 *
 * @see \Cms\Content\Models\Content
 * @package Cms\Content\Http\Resources\Content
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AllContentCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param Request $request
     *
     * @return array|Arrayable|JsonSerializable
     */
    public function toArray(Request $request): array|JsonSerializable|Arrayable
    {
        return parent::toArray($request);
    }
}
