<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `invoice_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum InvoiceStatusEnum: string
{
    /** id: 48 | name: Hoá đơn nháp | alias: Draft Invoice */
    case INVOICE_DRAFT = 'invoice_draft';

    /** id: 49 | name: Hoá đơn chờ thanh toán | alias: Pending Invoice */
    case INVOICE_PENDING = 'invoice_pending';

    /** id: 50 | name: Hoá đơn đã thanh toán | alias: Paid Invoice */
    case INVOICE_PAID = 'invoice_paid';

    /** id: 51 | name: Đã xuất hoá đơn | alias: Issued Invoice */
    case INVOICE_ISSUED = 'invoice_issued';

    /** id: 52 | name: Đã huỷ hoá đơn | alias: Cancelled Invoice */
    case INVOICE_CANCELLED = 'invoice_cancelled';

    /** id: 53 | name: Hoá đơn đã hoàn tiền | alias: Refunded Invoice */
    case INVOICE_REFUNDED = 'invoice_refunded';

    /** id: 54 | name: TT một phần - còn nợ | alias: Partially Paid Invoice */
    case INVOICE_PARTIALLY_PAID = 'invoice_partially_paid';

    /** id: 55 | name: Quá hạn thanh toán | alias: Overdue Invoice */
    case INVOICE_OVERDUE = 'invoice_overdue';

    /** id: 56 | name: Đã gửi hoá đơn | alias: Sent Invoice */
    case INVOICE_SENT = 'invoice_sent';
}