/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import axios from 'axios'
import { apiBase, COOKIE_KEY_LOCALE, COOKIE_KEY_TOKEN, defaultLocale } from './config'
import { iAlert } from '@/mixins'
import { cookie } from '@/config/cookies'
import { runtimeConfig } from '@/config/runtime'
import { isEmpty } from 'lodash-es'
import { noteDemoMarker } from '@/runtime/demoMode'

const axiosConfig = {
    baseURL: apiBase,
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
if (!isEmpty(token)) {
    axiosConfig.headers.Authorization = `Bearer ${token}`
}

// create a new axios instance
const ajax = axios.create(axiosConfig)

// Every request names the language the admin is read in now (the cookie follows the language switch) in
// `X-Admin-Locale`. The API uses it only for widget and design names; the API locale (content language, validation,
// mails) stays the site's, so `X-Locale` is never sent by default. A caller's own `X-Admin-Locale` is kept.
ajax.interceptors.request.use((config) => {
    if (!config.headers.has('X-Admin-Locale')) {
        config.headers.set('X-Admin-Locale', cookie.get(COOKIE_KEY_LOCALE, defaultLocale))
    }
    return config
})

ajax.interceptors.response.use(
    (response) => {
        noteDemoMarker(response)
        return response
    },
    (err) => {
        noteDemoMarker(err.response)
        //  If 401 => remove cookie token and reload the page
        if (err.response?.status === 401 && !isEmpty(token)) {
            //  Clear all storage & cookie
            localStorage.clear()
            cookie.remove(COOKIE_KEY_TOKEN)
            //  redirect to login page
            setTimeout(() => {
                window.location.reload()
            }, 1000)
        }
        // Log response errors. A request sent with `silent: true` (a background poll such as a sidebar counter) never shows an
        // error to the person; the caller gets the rejection and decides what to do with it.
        if (err.response?.data && !err.config?.silent) {
            iAlert(err.response.data, true)
            // Pages that catch this rejection and alert it again (`iAlert(err, true)`) stay quiet: one toast per failure.
            err.reported = true
        }
        return Promise.reject(err)
    }
)

export default ajax
