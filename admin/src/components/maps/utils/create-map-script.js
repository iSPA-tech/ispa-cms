/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export function createMapScript(options) {
    const googleMapScript = document.createElement('SCRIPT')
    if (typeof options !== 'object') {
        throw new Error('options should  be an object')
    }

    // libraries
    /* eslint-disable no-prototype-builtins */
    if (Array.prototype.isPrototypeOf(options.libraries)) {
        options.libraries = options.libraries.join(',')
    }
    options['callback'] = 'vueGoogleMapsInit'
    const baseUrl = 'https://maps.googleapis.com/maps/api/js?'

    const url =
        baseUrl +
        Object.keys(options)
            .map((key) => encodeURIComponent(key) + '=' + encodeURIComponent(options[key]))
            .join('&')

    googleMapScript.setAttribute('src', url)
    googleMapScript.setAttribute('async', '')
    googleMapScript.setAttribute('defer', '')

    return googleMapScript
}
