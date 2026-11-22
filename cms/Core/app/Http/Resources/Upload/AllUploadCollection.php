<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Upload;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Non-paginated collection of all Upload resources.
 *
 * Output: default parent toArray() (array of upload items)
 * Used by: CategoryResource, GalleryEditResource, and other resources needing image lists
 *
 * @see \Cms\Core\Models\Upload
 * @package Cms\Core\Http\Resources\Upload
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AllUploadCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  Request $request
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
