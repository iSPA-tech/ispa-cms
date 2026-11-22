<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum StatusEnum: string
{
    /** id: 102 | name: Lưu nháp | alias: Draft */
    case STATUS_DRAFT = 'status_draft';

    /** id: 103 | name: Chờ duyệt | alias: Pending */
    case STATUS_PENDING = 'status_pending';

    /** id: 104 | name: Công khai | alias: Published */
    case STATUS_PUBLISHED = 'status_published';

    /** id: 105 | name: Lưu trữ | alias: Archived */
    case STATUS_ARCHIVED = 'status_archived';

    public const DRAFT = self::STATUS_DRAFT;
    public const PENDING = self::STATUS_PENDING;
    public const PUBLISHED = self::STATUS_PUBLISHED;
    public const ARCHIVED = self::STATUS_ARCHIVED;
}
