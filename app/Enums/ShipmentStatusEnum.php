<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `shipment_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum ShipmentStatusEnum: string
{
    /** id: 57 | name: Đơn chờ xử lý | alias: Pending Shipment */
    case SHIPMENT_PENDING = 'shipment_pending';

    /** id: 58 | name: Đơn sẵn sàng giao | alias: Ready For Pickup Shipment */
    case SHIPMENT_READY_FOR_PICKUP = 'shipment_ready_for_pickup';

    /** id: 59 | name: Đang vận chuyển | alias: In Transit Shipment */
    case SHIPMENT_IN_TRANSIT = 'shipment_in_transit';

    /** id: 60 | name: Đơn đang giao | alias: Out For Delivery Shipment */
    case SHIPMENT_OUT_FOR_DELIVERY = 'shipment_out_for_delivery';

    /** id: 61 | name: Đơn đã gửi một phần | alias: Partially Shipped Shipment */
    case SHIPMENT_PARTIALLY_SHIPPED = 'shipment_partially_shipped';

    /** id: 62 | name: Đã giao cho 3PL | alias: 3PL Shipped Shipment */
    case SHIPMENT_SHIPPED = 'shipment_shipped';

    /** id: 63 | name: Đã giao thành công | alias: Delivered Shipment */
    case SHIPMENT_DELIVERED = 'shipment_delivered';

    /** id: 64 | name: Đơn giao chưa thành công | alias: Failed Attempt Shipment */
    case SHIPMENT_FAILED_ATTEMPT = 'shipment_failed_attempt';

    /** id: 65 | name: Đơn chuyển hoàn | alias: Returned Shipment */
    case SHIPMENT_RETURNED = 'shipment_returned';

    /** id: 66 | name: Đơn chuyển đã huỷ | alias: Cancelled Shipment */
    case SHIPMENT_CANCELLED = 'shipment_cancelled';

    /** id: 67 | name: Đơn bị thất lạc | alias: Lost Shipment */
    case SHIPMENT_LOST = 'shipment_lost';

    /** id: 68 | name: Đơn giao bị hỏng | alias: Damaged Shipment */
    case SHIPMENT_DAMAGED = 'shipment_damaged';
}