<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `content_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum ContentStatusEnum: string
{
    /** id: 22 | name: Bản nháp | alias: Draft content */
    case CONTENT_DRAFT = 'content_draft';

    /** id: 23 | name: Đang đợi dịch tự động | alias: Pending translation */
    case CONTENT_PENDING_TRANSLATION = 'content_pending_translation';

    /** id: 24 | name: Đã vào hàng đợi | alias: On queue */
    case CONTENT_ON_QUEUE_TRANSLATION = 'content_on_queue_translation';

    /** id: 25 | name: Đã dịch tự động | alias: Translated */
    case CONTENT_TRANSLATED = 'content_translated';

    /** id: 26 | name: Đã kiểm tra nội dung | alias: Content moderated */
    case CONTENT_MODERATED = 'content_moderated';

    /** id: 27 | name: Đã đăng tải công khai | alias: Content published */
    case CONTENT_PUBLISHED = 'content_published';

    /** id: 28 | name: Đã từ chối nội dung | alias: Content rejected */
    case CONTENT_REJECTED = 'content_rejected';
}