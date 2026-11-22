<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Constants;

/**
 * Database table name constants for the Content module.
 *
 * Centralizes all table name strings used by Content module models,
 * migrations, and queries.
 *
 * @see the crawler module table constants (client module)
 * @package Cms\Content\Constants
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 *
 * @api Content public API; signature changes follow the module notes.
 */
class ContentTableConstant
{
    /** User-saved bookmark references to content items */
    public const BOOKMARK = 'bookmarks';

    /** Rich content body entries (articles, pages) */
    public const CONTENTS = 'contents';

    /** Blog posts, news articles, and media entries */
    public const POSTS = 'posts';

    /** Taxonomy tag definitions */
    public const TAGS = 'tags';

    /** Tag-to-entity assignment pivot records */
    public const TAGS_DATA = 'tags_data';
}
