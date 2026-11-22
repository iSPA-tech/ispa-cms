<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Repositories;

use Cms\Content\Interfaces\TagInterface;
use Cms\Core\Models\Tag;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for content tags.
 *
 * Implements {@see TagInterface} for {@see Tag} persistence. Every query is limited to post tags
 * (`type = post`); the inner object is a post-scoped builder, not a bare model.
 *
 * @see Tag
 * @see TagInterface
 * @package Cms\Content\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TagRepository extends RepositoriesAbstract implements TagInterface
{
    /**
     * Initialize the repository and bind the Tag model.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(Tag::class)->reset();
    }

    /**
     * Start a fresh query limited to post tags, so user tags never surface through Content.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->inner = Tag::query()->ofType(Tag::TYPE_POST);

        return $this;
    }

    /**
     * Table of the tag model (the inner object is a query builder here).
     *
     * @return string
     */
    public function table(): string
    {
        return (new Tag())->getTable();
    }
}
