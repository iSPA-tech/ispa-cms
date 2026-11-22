<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `project_type`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum ProjectTypeEnum: string
{
    /** id: 74 | name: Cao tầng | alias: High-rise */
    case PROJECT_TYPE_HIGHRISE = 'project_type_highrise';

    /** id: 75 | name: Thấp tầng | alias: Low-rise */
    case PROJECT_TYPE_LOWRISE = 'project_type_lowrise';

    /** id: 76 | name: Khu phức hợp | alias: Mixed-use Complex */
    case PROJECT_TYPE_MIXEDUSE = 'project_type_mixeduse';

    /** id: 77 | name: Khu nghỉ dưỡng | alias: Resort */
    case PROJECT_TYPE_RESORT = 'project_type_resort';

    /** id: 78 | name: Khu đô thị | alias: Urban Area */
    case PROJECT_TYPE_URBAN = 'project_type_urban';
}