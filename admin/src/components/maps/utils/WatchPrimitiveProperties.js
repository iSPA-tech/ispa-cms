/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Watch the individual properties of a PoD object, instead of the object
 * per se. This is different from a deep watch where both the reference
 * and the individual values are watched.
 *
 * In effect, it throttles the multiple $watch to execute at most once per tick.
 */

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export default function WatchPrimitiveProperties(
    vueInst,
    propertiesToTrack,
    handler,
    immediate = false
) {
    let isHandled = false

    function requestHandle() {
        if (!isHandled) {
            isHandled = true
            vueInst.$nextTick(() => {
                isHandled = false
                handler()
            })
        }
    }

    for (const prop of propertiesToTrack) {
        vueInst.$watch(prop, requestHandle, { immediate })
    }
}
