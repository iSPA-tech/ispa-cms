<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

/**
 * Native enum generated from enumerates type `storage_status`.
 *
 * Sort order: source rows are ordered by id ascending for deterministic code generation.
 */
enum StorageStatusEnum: string
{
    /** id: 20 | name: Nguyên vẹn | alias: Intact */
    case STORAGE_STATUS_INTACT = 'storage_status_intact';

    /** id: 21 | name: Hư hỏng | alias: Broken */
    case STORAGE_STATUS_BROKEN = 'storage_status_broken';
}