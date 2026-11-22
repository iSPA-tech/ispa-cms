<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `moderation_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum ModerationStatusEnum: string
{
    /** id: 112 | name: Đã duyệt | alias: Approved */
    case MODERATION_APPROVED = 'moderation_approved';

    /** id: 113 | name: Từ chối | alias: Rejected */
    case MODERATION_REJECTED = 'moderation_rejected';

    /** id: 114 | name: Đã gửi | alias: Submitted */
    case MODERATION_SUBMITTED = 'moderation_submitted';

    /** id: 115 | name: Đã vào hàng đợi | alias: Queued */
    case MODERATION_QUEUED = 'moderation_queued';

    /** id: 116 | name: Đang kiểm duyệt | alias: Reviewing */
    case MODERATION_REVIEWING = 'moderation_reviewing';

    /** id: 117 | name: Đã áp dụng | alias: Applied */
    case MODERATION_APPLIED = 'moderation_applied';

    /** id: 118 | name: Lỗi xử lý | alias: Failed */
    case MODERATION_FAILED = 'moderation_failed';
}