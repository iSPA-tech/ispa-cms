<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface;

/**
 * Contract for MetaAttribute entity CRUD and query operations.
 *
 * Implemented by: {@see MetaAttributeRepository}.
 * Used for: {@see MetaAttribute} data access (attribute definitions in the meta system).
 *
 * @see \Cms\Core\Repositories\MetaAttributeRepository
 * @see \Cms\Core\Models\MetaAttribute
 *
 * @package Cms\Core\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface MetaAttributeInterface extends RepositoryInterface
{

}
