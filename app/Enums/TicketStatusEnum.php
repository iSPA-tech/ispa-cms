<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `ticket_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum TicketStatusEnum: string
{
    /** id: 10 | name: Mới | alias: New */
    case TICKET_NEW = 'ticket_new';

    /** id: 11 | name: Đợi | alias: Pending */
    case TICKET_PENDING = 'ticket_pending';

    /** id: 12 | name: Đang xử lý | alias: Processing */
    case TICKET_PROCESSING = 'ticket_processing';

    /** id: 13 | name: Đã hoàn tất | alias: Finished */
    case TICKET_FINISHED = 'ticket_finished';

    /** id: 14 | name: Từ chối | alias: Denied */
    case TICKET_DENIED = 'ticket_denied';

    /** id: 15 | name: Bỏ qua | alias: Ignored */
    case TICKET_IGNORED = 'ticket_ignored';
}