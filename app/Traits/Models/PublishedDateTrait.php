<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Traits\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait PublishedDateTrait
{
    /**
     * Get published date
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-05-01, 11:26 ICT
     */
    public function publishedDate(): Attribute
    {
        return new Attribute(
            get: fn () => now()->parse($this->published_at ?? $this->created_at)
        );
    }
}
