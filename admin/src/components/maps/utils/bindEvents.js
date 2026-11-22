/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export default (vueInst, googleMapsInst, events) => {
    for (const eventName of events) {
        const propName = `on${eventName.charAt(0).toUpperCase()}${eventName.slice(1)}`.replace(
            /[-_]+(.)?/g,
            (_, c) => (c ? c.toUpperCase() : '')
        )

        if (vueInst.$props[propName] || vueInst.$attrs[propName]) {
            googleMapsInst.addListener(eventName, (ev) => {
                vueInst.$emit(eventName, ev)
            })
        } else if (vueInst.$gmapOptions.autobindAllEvents || vueInst.$attrs[eventName]) {
            googleMapsInst.addListener(eventName, (ev) => {
                vueInst.$emit(eventName, ev)
            })
        }
    }
}
