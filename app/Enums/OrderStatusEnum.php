<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `order_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum OrderStatusEnum: string
{
    /** id: 30 | name: Đơn chưa xác nhận | alias: Pending Order */
    case ORDER_PENDING = 'order_pending';

    /** id: 31 | name: Đơn nháp | alias: Draft Order */
    case ORDER_DRAFT = 'order_draft';

    /** id: 32 | name: Đơn đã hoàn tất | alias: Completed Order */
    case ORDER_COMPLETED = 'order_completed';

    /** id: 33 | name: Đơn đang xử lý | alias: Processing Order */
    case ORDER_PROCESSING = 'order_processing';

    /** id: 34 | name: Đơn đã huỷ | alias: Cancelled Order */
    case ORDER_CANCELED = 'order_canceled';

    /** id: 35 | name: Đơn đã đóng | alias: Closed Order */
    case ORDER_CLOSED = 'order_closed';
}