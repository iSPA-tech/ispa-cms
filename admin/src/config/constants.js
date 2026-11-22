/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { buildVersion, domain } from '@/config/base'
//  Get week number by dayjs
import dayjs from 'dayjs'
import weekOfYear from 'dayjs/plugin/weekOfYear'

dayjs.extend(weekOfYear)

const cacheDomainSlug =
    (domain || 'app')
        .toString()
        .trim()
        .toLowerCase()
        .replace(/(^\w+:|^)\/\//, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '') || 'app'

const currentWeek = dayjs().week()

/** Time zone the admin shows and submits date-times in (the zone the server reads submitted values in). */
export const ADMIN_TIMEZONE = 'Asia/Ho_Chi_Minh'

export const CATEGORY_TYPE_STR_POST = 'article'
export const CATEGORY_TYPE_STR_PRODUCT = 'product'
export const CATEGORY_TYPE_STR_GALLERY = 'gallery'
export const CATEGORY_TYPE_STR_PLACE = 'place'
export const CATEGORY_TYPE_STR_EVENT = 'event'
export const CATEGORY_TYPE_STR_UPLOAD = 'upload'
export const CONTENT_TYPE_STRING = 'content'

export const CATEGORY_TYPE_INT_POST = 0
export const CATEGORY_TYPE_INT_PRODUCT = 4
export const CATEGORY_TYPE_INT_GALLERY = 2
export const CATEGORY_TYPE_INT_PLACE = 1
export const CATEGORY_TYPE_INT_EVENT = 3
export const CATEGORY_TYPE_INT_UPLOAD = 5

export const CATEGORY_TYPE = {
    [CATEGORY_TYPE_STR_POST]: CATEGORY_TYPE_INT_POST,
    [CATEGORY_TYPE_STR_PRODUCT]: CATEGORY_TYPE_INT_PRODUCT,
    [CATEGORY_TYPE_STR_GALLERY]: CATEGORY_TYPE_INT_GALLERY,
    [CATEGORY_TYPE_STR_PLACE]: CATEGORY_TYPE_INT_PLACE,
    [CATEGORY_TYPE_STR_EVENT]: CATEGORY_TYPE_INT_EVENT,
    [CATEGORY_TYPE_STR_UPLOAD]: CATEGORY_TYPE_INT_UPLOAD,
}

export const defaultStatusValue = {
    status: 'status_published',
    content_status: 'all',
    storage: 'status_published',
    organization_status: 'organization_status_is_reviewed',
    organization_verified: 'organization_verified_confirmed',
    user_status: 'user_active',
    ticket_status: 'ticket_new',
    ticket_label: 'label_priority',
    storage_status: 'storage_status_intact',
}
export const defaultFilterParams = {
    page: 1,
    pageSize: 20,
    keywords: '',
    languageId: null,
    categoryId: 'all',
    status: 'all',
    dateRange: [null, null],
    view_folder: '',
}

export const CURRENT_VERSION_KEY = 'iSpa-app-current-version'
export const CACHE_KEY_PREFIX = `iSpa-app-${cacheDomainSlug}-`
export const CACHE_KEY_MESSAGES_LOCALE = `${CACHE_KEY_PREFIX}messages-${buildVersion}`
export const CACHE_KEY_ACCOUNT_DATA = `${CACHE_KEY_PREFIX}account-data-${buildVersion}`
export const CACHE_KEY_GLOBAL_SETTINGS = `${CACHE_KEY_PREFIX}global-settings-${buildVersion}`
export const CACHE_KEY_LANGUAGES = `${CACHE_KEY_PREFIX}languages-data-${buildVersion}`
export const CACHE_KEY_ACTIVE_MODULES = `${CACHE_KEY_PREFIX}active-modules-${buildVersion}`
export const CACHE_KEY_DOMAIN = `${CACHE_KEY_PREFIX}domain-token-${currentWeek}`
export const COOKIE_KEY_LOCALE = `${CACHE_KEY_PREFIX}locale-${buildVersion}`
export const COOKIE_KEY_TOKEN = `${CACHE_KEY_PREFIX}token`
export const COOKIE_KEY_CMS_ADMIN_THEME = `${CACHE_KEY_PREFIX}cms-admin-theme`
export const COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN = `${CACHE_KEY_PREFIX}token-original`
export const COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE = `${CACHE_KEY_PREFIX}locale-original`
export const CACHE_KEY_SIGN_IN_AS_META = `${CACHE_KEY_PREFIX}sign-in-as-meta-${buildVersion}`

export const CONTENT_STATUSES = {
    DRAFT: 'content_draft',
    PENDING_TRANSLATION: 'content_pending_translation',
    ON_QUEUE_TRANSLATION: 'content_on_queue_translation',
    TRANSLATED: 'content_translated',
    MODERATED: 'content_moderated',
    PUBLISHED: 'content_published',
    REJECTED: 'content_rejected',
}
