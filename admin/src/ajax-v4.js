/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import axios from 'axios'
import { apiBase, defaultLocale, COOKIE_KEY_LOCALE, COOKIE_KEY_TOKEN } from './config'
import { iAlert } from '@/mixins'
import { cookie } from '@/config/cookies'
import { runtimeConfig } from '@/config/runtime'

const axiosConfig = {
    baseURL: apiBase.replace('v2', 'v4').toString(),
    timeout: 120000,
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Credentials': 'true',
        'Accept-Language': cookie.get(COOKIE_KEY_LOCALE, defaultLocale),
        'ISPA-ADMIN-AUTH-KEY': runtimeConfig().adminAuthKey,
    },
}
const token = cookie.get(COOKIE_KEY_TOKEN)
if (token !== null && token !== undefined) {
    axiosConfig.headers.Authorization = `Bearer ${token}`
}

// create a new axios instance
const ajaxV4 = axios.create(axiosConfig)

// Same as `ajax.js`: every request names the admin language in `X-Admin-Locale` (widget and design names only).
ajaxV4.interceptors.request.use((config) => {
    if (!config.headers.has('X-Admin-Locale')) {
        config.headers.set('X-Admin-Locale', cookie.get(COOKIE_KEY_LOCALE, defaultLocale))
    }
    return config
})

ajaxV4.interceptors.response.use(
    (response) => {
        return response
    },
    (err) => {
        //  If 401 => remove cookie token and reload the page
        if (err.response?.status === 401) {
            //  Clear all storage & cookie
            // localStorage.clear()
            // cookie.remove(COOKIE_KEY_TOKEN)
            // //  redirect to login page
            // setTimeout(() => {
            //     window.location.reload()
            // }, 1000)
        }
        // Log response errors
        if (err.response?.data) {
            iAlert(err.response.data, true)
        }
        return Promise.reject(err)
    }
)

export default ajaxV4
