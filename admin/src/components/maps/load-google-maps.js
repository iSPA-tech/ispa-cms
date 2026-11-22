/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { Env } from './utils/env'
import { createMapScript } from './utils/create-map-script'

let isApiSetUp = false

export function loadGMapApi(options) {
    if (Env.isServer()) {
        return
    }

    if (!isApiSetUp) {
        isApiSetUp = true
        const googleMapScript = createMapScript(options)
        document.head.appendChild(googleMapScript)
    } else {
        throw new Error('You already started the loading of google maps')
    }
}
