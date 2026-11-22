<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `organization_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum OrganizationStatusEnum: string
{
    /** id: 106 | name: Chưa xem xét | alias: Not reviewed yet */
    case ORGANIZATION_STATUS_AWAITING_REVIEW = 'organization_status_awaiting_review';

    /** id: 107 | name: Đã xem xét | alias: Is reviewed */
    case ORGANIZATION_STATUS_IS_REVIEWED = 'organization_status_is_reviewed';
}