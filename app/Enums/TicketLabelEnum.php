<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `ticket_label`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum TicketLabelEnum: string
{
    /** id: 16 | name: Bình thường | alias: Normal */
    case LABEL_NORMAL = 'label_normal';

    /** id: 17 | name: Cao | alias: High */
    case LABEL_HIGH = 'label_high';

    /** id: 18 | name: Thấp | alias: Low */
    case LABEL_LOW = 'label_low';

    /** id: 19 | name: Ưu tiên | alias: Priority */
    case LABEL_PRIORITY = 'label_priority';
}