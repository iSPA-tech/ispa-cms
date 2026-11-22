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
 * Contract for Field entity CRUD and specialized form-field queries.
 *
 * Implemented by: {@see FieldRepository}.
 * Used for: {@see Field} data access, including cached lookup by form ID.
 *
 * @see \Cms\Core\Repositories\FieldRepository
 * @see \Cms\Core\Models\Field
 *
 * @package Cms\Core\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface FieldInterface extends BaseRepositoryInterface
{
    /**
     * Get field by form_id
     *
     * @param string|null $formId
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-29, 21:53 ICT
     */
    public function getFieldByFormId(string $formId = null, bool $freshCache = false): mixed;
}
