<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Bulk\Handlers;

use App\Enums\StatusEnum;

/**
 * Bulk handler that sets the status to 'draft' for selected models.
 *
 * Defaults to StatusEnum::DRAFT->value. Extends AbstractStatusHandler
 * for transactional status updates.
 *
 * @see AbstractStatusHandler
 * @package Cms\Core\Services\Bulk\Handlers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class GenericStatusUnpublishHandler extends AbstractStatusHandler
{
    /**
     * Initialize the handler with model class, ability, status value, and optional post-action callback.
     *
     * @param string        $modelClass  The Eloquent model class to operate on.
     * @param string        $ability     The policy ability required (default: 'update').
     * @param string        $statusValue The status value to set (default: STATUS_DRAFT).
     * @param \Closure|null $afterAction Optional callback executed after each model update.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        string $modelClass,
        string $ability = 'update',
        ?string $statusValue = null,
        ?\Closure $afterAction = null
    ) {
        parent::__construct($modelClass, $statusValue ?? StatusEnum::DRAFT->value, $ability, $afterAction);
    }
}
