<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services\Pipelines;

use Closure;
use App\Services\Upload\AttachAndDetachImages;

class DetachImagesInsideContent
{
    /** @var bool */
    public bool $isDiscovery = false;

    public function handle($model, Closure $next)
    {
        AttachAndDetachImages::detachImagesInsideContent($model);

        return $next($model);
    }
}
