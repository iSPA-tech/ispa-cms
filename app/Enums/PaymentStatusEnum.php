<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `payment_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum PaymentStatusEnum: string
{
    /** id: 36 | name: Chưa thanh toán | alias: Unpaid */
    case PAYMENT_UNPAID = 'payment_unpaid';

    /** id: 37 | name: Đã được chấp thuận | alias: Authorized */
    case PAYMENT_AUTHORIZED = 'payment_authorized';

    /** id: 38 | name: Đã thanh toán | alias: Paid */
    case PAYMENT_PAID = 'payment_paid';

    /** id: 39 | name: Đã thanh toán một phần | alias: Partially Paid */
    case PAYMENT_PARTIALLY_PAID = 'payment_partially_paid';

    /** id: 40 | name: Đã hoàn tiền một phần | alias: Partially Refunded */
    case PAYMENT_PARTIALLY_REFUNDED = 'payment_partially_refunded';

    /** id: 41 | name: Đã hoàn tiền | alias: Refunded */
    case PAYMENT_REFUNDED = 'payment_refunded';

    /** id: 42 | name: Thanh toán lỗi | alias: Failed */
    case PAYMENT_FAILED = 'payment_failed';

    /** id: 43 | name: Huỷ giao dịch | alias: Voided */
    case PAYMENT_VOIDED = 'payment_voided';
}