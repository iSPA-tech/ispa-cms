<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface;

/**
 * Contract for tag persistence operations.
 *
 * Extends the base repository for Tag CRUD and lookup.
 * Implemented by {@see \Cms\Content\Repositories\TagRepository}.
 *
 * @package Cms\Content\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 *
 * @api Content public API; signature changes follow the module notes.
 */
interface TagInterface extends RepositoryInterface
{

}
