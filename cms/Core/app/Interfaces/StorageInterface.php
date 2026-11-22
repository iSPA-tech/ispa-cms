<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface as BaseRepositoryInterface;

/**
 * Contract for Storage entity CRUD and query operations.
 *
 * Implemented by: {@see StorageRepository}.
 * Used for: {@see Storage} data access (file/media storage records).
 *
 * @see \Cms\Core\Repositories\StorageRepository
 * @see \Cms\Core\Models\Storage
 *
 * @package Cms\Core\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface StorageInterface extends BaseRepositoryInterface
{
}
