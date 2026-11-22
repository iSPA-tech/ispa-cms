<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `fulfillment_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum FulfillmentStatusEnum: string
{
    /** id: 44 | name: Đã xử lý một phần | alias: Partially Fulfilled */
    case FULFILLMENT_PARTIALLY_FULFILLED = 'fulfillment_partially_fulfilled';

    /** id: 45 | name: Chưa xử lý | alias: Unfulfilled */
    case FULFILLMENT_UNFULFILLED = 'fulfillment_unfulfilled';

    /** id: 46 | name: Đã xử lý | alias: Fulfilled */
    case FULFILLMENT_FULFILLED = 'fulfillment_fulfilled';

    /** id: 47 | name: Đã gửi hàng | alias: Shipped */
    case FULFILLMENT_SHIPPED = 'fulfillment_shipped';

    /** id: 69 | name: Đang giao hàng | alias: Out For Delivery */
    case FULFILLMENT_OUT_FOR_DELIVERY = 'fulfillment_out_for_delivery';

    /** id: 70 | name: Đã giao hàng | alias: Delivered */
    case FULFILLMENT_DELIVERED = 'fulfillment_delivered';

    /** id: 71 | name: Đã trả hàng | alias: Returned */
    case FULFILLMENT_RETURNED = 'fulfillment_returned';

    /** id: 72 | name: Đã trả hàng một phần | alias: Partially Returned */
    case FULFILLMENT_PARTIALLY_RETURNED = 'fulfillment_partially_returned';
}