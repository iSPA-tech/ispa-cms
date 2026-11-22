/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// How many shared areas the active theme declares (GET /slots `meta.area_count`), shared by the menu entry and the
// editor. The menu entry "Khu vực chung" shows only when the number is above 0.

import { ref } from 'vue'

/** null while unknown (the entry stays hidden until the number is known). */
export const areaCount = ref(null)

let requested = false

/** The editor already read the areas: hand the number over instead of asking twice. */
export function setAreaCount(count) {
    requested = true
    areaCount.value = Number(count) || 0
}

/**
 * `hidden` of the menu entry: read on every render. The first call asks the server once, in the background and
 * silently (a refused or failed read counts as no area); the entry appears when the answer is above 0.
 *
 * @returns {boolean}
 */
export function areaNavHidden() {
    if (!requested) {
        requested = true
        // Loaded on demand: the menu configuration is read at boot, before the API client's own dependencies.
        import('./api')
            .then((api) => api.getAreaCount())
            .then((count) => {
                areaCount.value = Number(count) || 0
            })
            .catch(() => {
                areaCount.value = 0
            })
    }

    return !(areaCount.value > 0)
}

/** Test seam: forget the cached number. */
export function resetAreaCount() {
    requested = false
    areaCount.value = null
}
