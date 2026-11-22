<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Observers;

use Cms\Core\Models\Category;
use Cms\Core\Observers\AbstractObserver;

/**
 * Observer registering model events for Category via the shared AbstractObserver.
 * @package Cms\Core\Observers
 */
final class CategoryObserver extends AbstractObserver
{
    protected function modelClass(): string
    {
        return Category::class;
    }
}
