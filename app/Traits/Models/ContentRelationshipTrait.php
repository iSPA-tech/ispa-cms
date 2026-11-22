<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Traits\Models;

use Cms\Content\Models\Content;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait ContentRelationshipTrait
{
    /**
     * Provinces relationship
     *
     * @return morphOne
     * @author malayvuong
     * @since 1.0.0
     */
    public function content(): morphOne
    {
        return $this->morphOne(Content::class, 'target')->where('language_id', lang('id', get_lang_id()));
    }

    /**
     * Content relationship
     *
     * @return morphMany
     * @author malayvuong
     * @since 1.0.0
     */
    public function contents(): morphMany
    {
        return $this->morphMany(Content::class, 'target');
    }
}
