/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

/**
 * Fetch the import definition metadata of one module entity (generic: any module may offer imports).
 */
export const getImportDefinitionMetadata = async (module, entity) => {
    return await ajax
        .get(`import-definitions/${module}/${entity}`)
        .then((res) => res.data?.data || res.data || {})
}
