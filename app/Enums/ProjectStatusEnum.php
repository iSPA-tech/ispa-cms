<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `project_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum ProjectStatusEnum: string
{
    /** id: 84 | name: Sắp mở bán | alias: Upcoming */
    case PROJECT_STATUS_UPCOMING = 'project_status_upcoming';

    /** id: 85 | name: Đang mở bán | alias: On Sale */
    case PROJECT_STATUS_ON_SALE = 'project_status_on_sale';

    /** id: 86 | name: Đang xây dựng | alias: Under Construction */
    case PROJECT_STATUS_UNDER_CONSTRUCTION = 'project_status_under_construction';

    /** id: 87 | name: Cao tầng | alias: High-rise */
    case PROJECT_STATUS_HIGHRISE = 'project_status_highrise';

    /** id: 88 | name: Đã hết hàng | alias: Sold Out */
    case PROJECT_STATUS_SOLD_OUT = 'project_status_sold_out';

    /** id: 89 | name: Đã bàn giao | alias: Completed */
    case PROJECT_STATUS_COMPLETED = 'project_status_completed';
}