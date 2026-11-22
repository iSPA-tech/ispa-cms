<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `project_segment`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum ProjectSegmentEnum: string
{
    /** id: 79 | name: Bình dân | alias: Affordable */
    case PROJECT_SEGMENT_AFFORDABLE = 'project_segment_affordable';

    /** id: 80 | name: Trung cấp | alias: Mid-range */
    case PROJECT_SEGMENT_MIDRANGE = 'project_segment_midrange';

    /** id: 81 | name: Cao cấp | alias: Premium */
    case PROJECT_SEGMENT_PREMIUM = 'project_segment_premium';

    /** id: 82 | name: Hạng sang | alias: Luxury */
    case PROJECT_SEGMENT_LUXURY = 'project_segment_luxury';

    /** id: 83 | name: Siêu sang | alias: Ultra Luxury */
    case PROJECT_SEGMENT_ULTRA_LUXURY = 'project_segment_ultra_luxury';
}