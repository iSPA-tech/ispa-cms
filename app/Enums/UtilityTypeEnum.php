<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `utility_type`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum UtilityTypeEnum: string
{
    /** id: 90 | name: Tiện ích chung | alias: General Amenities */
    case UTILITY_GENERAL = 'utility_general';

    /** id: 91 | name: Tiện ích nội khu | alias: Internal Amenities */
    case UTILITY_INTERNAL = 'utility_internal';

    /** id: 92 | name: Tiện ích ngoại khu | alias: External Amenities */
    case UTILITY_EXTERNAL = 'utility_external';

    /** id: 93 | name: Nội thất | alias: Furniture */
    case UTILITY_FURNITURE = 'utility_furniture';

    /** id: 94 | name: Thiết bị | alias: Equipment */
    case UTILITY_EQUIPMENT = 'utility_equipment';

    /** id: 95 | name: Cảnh quan & môi trường | alias: Landscape & Environment */
    case UTILITY_LANDSCAPE = 'utility_landscape';

    /** id: 96 | name: An ninh & quản lý | alias: Security & Management */
    case UTILITY_SECURITY = 'utility_security';

    /** id: 97 | name: Giao thông & hạ tầng | alias: Traffic & Infrastructure */
    case UTILITY_INFRASTRUCTURE = 'utility_infrastructure';
}