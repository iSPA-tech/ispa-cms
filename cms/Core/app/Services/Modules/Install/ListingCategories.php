<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

/**
 * The one list of store listing categories. The hub's listing request and draft importer validate against
 * it, the hub editor offers exactly these (the admin API sends the list), and a site drops any other value a hub sends
 * (an older hub or a bad draft) so no store card ever shows an unknown category.
 */
final class ListingCategories
{
    /** Category slugs; each has the admin label `text.platform.listing.category_<slug with _ for ->`. */
    public const SLUGS = ['content', 'commerce', 'education', 'community', 'marketing', 'real-estate', 'tools'];

    /**
     * A category value kept only when it is one of the known slugs.
     */
    public static function normalize(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::SLUGS, true) ? $value : null;
    }
}
