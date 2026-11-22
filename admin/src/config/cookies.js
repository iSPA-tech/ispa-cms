/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { basePath, cookieDomain, isLocal } from './base'
import { runtimeConfig } from './runtime'
import { useCookies } from '@vueuse/integrations/useCookies'

const cookies = useCookies(['iSpa-cms', import.meta.env.VITE_BUILD_VERSION])

const parsedBasePath =
    basePath.length > 1 && basePath.endsWith('/') ? basePath.slice(0, -1) : basePath

const shouldSetDomain = !isLocal && !!cookieDomain
const cookiesConfig = {
    path: parsedBasePath,
    ...(shouldSetDomain ? { domain: cookieDomain } : {}),
    secure: !isLocal && runtimeConfig().secureCookie,
    sameSite: 'lax',
}

export const cookie = {
    get: (key, defaultValue = null) => {
        const value = cookies.get(key, cookiesConfig)

        if (typeof value === 'undefined') {
            cookies.set(key, defaultValue, cookiesConfig)
            return defaultValue
        }

        return value
    },
    set: (key, value, option = {}) => {
        const newOption = { ...cookiesConfig, ...option }
        cookies.set(key, value, newOption)
    },
    remove: (key) => {
        cookies.remove(key, cookiesConfig)
    },
    getAll: () => {
        return cookies.getAll(cookiesConfig)
    },
}

export default cookiesConfig
