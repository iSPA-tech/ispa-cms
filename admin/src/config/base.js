/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * App version is injected from root VERSION through vite.config.js.
 *
 * @author malayvuong
 * @since 6.0.0 - 2022-02-14, 11:56
 */

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { runtimeConfig } from '@/config/runtime'

// Per-build id (release + rebuild timestamp on runtime rebuilds); keys the admin i18n message cache.
export const buildVersion = import.meta.env.VITE_BUILD_VERSION || '000000'

// Site values come from run-time config (window.__ISPA_ADMIN__ -> VITE_* -> location), see runtime.js.
const runtime = runtimeConfig()

// Site version (the VERSION file through config.js); the admin i18n packs are generated under it.
export const appVersion = runtime.version || '2026.03.0'

export const cookieDomain = runtime.cookieDomain || 'localhost'

export const isLocal = import.meta.env.VITE_NODE_ENV !== 'production'

export const url = runtime.url || 'http://localhost:3000/'

// Host (with port) of the site url; used for cache and cookie key names.
export const domain = (() => {
    try {
        return new URL(url).host
    } catch {
        return 'localhost:3000'
    }
})()

// export const basePath = ['development', 'production'].includes(import.meta.env.VITE_NODE_ENV)
//     ? '/iPanel/'
//     : '/'
export const basePath = '/iPanel/'

export const apiBase = url + 'api/v2/admin/'

//  Remove last trailing slash
export const apiUrl = url
export const cmsVersion =
    (import.meta.env.VITE_NODE_ENV === 'production' ? '' : 'dev-') + appVersion

export const supportLocales = ['en', 'vi']
// A build without VITE_DEFAULT_LOCALE (or with an unsupported value) used to leave this undefined: the first visit then
// asked the server for `i18n/adm/null/*.json` and every pack request answered 404.
export const defaultLocale = supportLocales.includes(import.meta.env.VITE_DEFAULT_LOCALE)
    ? import.meta.env.VITE_DEFAULT_LOCALE
    : 'en'

export const logoUrl = 'https://ispa.io/img/logo-square.png'
export const logoAuthUrl = 'https://ispa.io/img/logo.png'
export const logoDarkUrl = 'https://ispa.io/img/logo-dark.png'

export const cmsTitle = 'iSPA CMS'
export const projectTitle =
    'Content Management System' + (import.meta.env.VITE_NODE_ENV === 'development' ? ' DEV' : '')

export const cookieKey =
    projectTitle.replaceAll(' ', '-').toLowerCase() +
    '-' +
    domain.replaceAll('.', '-').replace(':', '-')

//  Pusher
export const PusherId = '8f49f3fd5d38be55ca9d'
export const PusherCluster = 'us2'
export const PusherDebug = import.meta.env.VITE_NODE_ENV !== 'production'
