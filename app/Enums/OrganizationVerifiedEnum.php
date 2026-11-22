<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `organization_verified`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum OrganizationVerifiedEnum: string
{
    /** id: 108 | name: Đang xác minh thông tin | alias: Information is being verified */
    case ORGANIZATION_VERIFIED_AWAITING = 'organization_verified_awaiting';

    /** id: 109 | name: Đã xác minh thông tin | alias: Is verified */
    case ORGANIZATION_VERIFIED_CONFIRMED = 'organization_verified_confirmed';
}