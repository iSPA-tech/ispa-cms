/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Permission utilities - Re-exported from hooks/useCan.js
 *
 * This file maintains backward compatibility. All permission logic has been
 * moved to @/hooks/useCan.js for better organization and reusability.
 *
 * @see @/hooks/useCan.js for full documentation and examples
 */

export {
    // Core API
    can,
    canAny,
    canAll,
    canIn,

    // Spec builders
    mod,
    res,
    act,
    legacy,
    anyOf,
    allOf,
    crud,
    read,
    write,

    // Debug helpers
    explainDeny,
    assertCan,

    // Constants
    ACTIONS,
    CRUD_ACTIONS,
    READ_ACTIONS,
    WRITE_ACTIONS,

    // Index (for advanced usage)
    usePermissionIndex,

    // Legacy (deprecated)
    rule,
} from '@/hooks/useCan'

// Default export for convenience
export { default } from '@/hooks/useCan'
