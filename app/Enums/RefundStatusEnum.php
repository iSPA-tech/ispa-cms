<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `refund_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum RefundStatusEnum: string
{
    /** id: 98 | name: Hoàn tiền nháp | alias: Draft Refund */
    case REFUND_DRAFT = 'refund_draft';

    /** id: 99 | name: Đã hoàn tiền | alias: Refunded */
    case REFUND_REFUNDED = 'refund_refunded';

    /** id: 101 | name: Hoàn tiền hoàn tất | alias: Completed Refund */
    case REFUND_COMPLETED = 'refund_completed';
}