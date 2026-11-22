<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Bulk;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for bulk action handlers in the CMS.
 *
 * Implementations define validation rules, authorization logic, and
 * execution behavior for bulk operations on collections of entities.
 * Supports dry-run mode for previewing effects without persistence.
 *
 * @see BulkRegistry
 * @see BulkResult
 * @package Cms\Core\Services\Bulk
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface BulkActionHandler
{
    /**
     * Return validation rules for the bulk payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Authorize the bulk action.
     *
     * Implementations should throw an AuthorizationException on failure.
     * @param  Authenticatable|null $user
     * @param  array $payload
     * @return void
     */
    public function authorize(?Authenticatable $user, array $payload): void;

    /**
     * Execute the bulk action.
     *
     * Implementations must not mutate storage when $dryRun is true.
     *
     * @param  array $payload
     * @param  bool $dryRun
     * @return BulkResult|array<string, mixed>
     */
    public function handle(array $payload, bool $dryRun): BulkResult|array;
}
