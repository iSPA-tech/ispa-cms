/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { notification } from 'ant-design-vue'
import numeral from 'numeral'
import { useStorage } from '@vueuse/core'
import { basePath, defaultLocale } from '@/config/base'
import ajax from '@/ajax'
import { h } from 'vue'
import { isArray, isEmpty, isObject } from 'lodash-es'
import Spinner from '@/assets/spinner.png'
import { CACHE_KEY_ACCOUNT_DATA, CONTENT_STATUSES, cookie, COOKIE_KEY_LOCALE } from '@/config'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import { widgetInputDefault } from '@/hooks'
import { can } from '@/hooks/useCan'

dayjs.extend(utc)
dayjs.extend(timezone)

export const runSubmitOnce = async (state, callback) => {
    if (state?.value) {
        return undefined
    }

    state.value = true

    try {
        return await callback()
    } finally {
        state.value = false
    }
}

//  Deep clone object
export const deepCloneObj = (obj) => {
    return JSON.parse(JSON.stringify(obj))
}

// Merge input
export const mergeInput = (existing, updated) => {
    return {
        ...existing,
        type: existing.type || updated.type,
        default_value: updated.default_value,
        options: {
            ...existing.options,
            ...updated.options,
        },
    }
}

//  Regex inputs from template and current inputs
export const getInputVariableByTemplate = (currentFormInputs = [], formTemplates = []) => {
    const inputsMap = new Map()
    const currentInputs = currentFormInputs.filter((item) => item.name)

    const addOrMergeInput = (inputName, inputData) => {
        const existedIndex = currentInputs.findIndex((e) => e.name === inputName)
        const existing = existedIndex >= 0 ? currentInputs[existedIndex] : null
        inputsMap.set(inputName, existing ? mergeInput(existing, inputData) : inputData)
    }

    formTemplates.forEach((item) => {
        const rawContent = item.content || ''

        // {repeat:name template=`...`} - the inner template belongs to the repeater, so every
        // other pass below runs on content with these blocks removed.
        const repeatRegex = /\{repeat:([a-z][a-z0-9_]*)\s+template=`([\s\S]*?)`}/g
        let match
        while ((match = repeatRegex.exec(rawContent)) !== null) {
            addOrMergeInput(match[1], {
                ...widgetInputDefault(),
                name: match[1],
                type: 'repeater',
                default_value: [],
                options: {},
            })
        }
        const content = rawContent.replace(repeatRegex, '')

        // 1. {query:... variable="$x" limit="y" template=`...`}
        const queryRegex =
            /\{query:([a-zA-Z0-9_.]+)\s+variable="(\$[a-zA-Z0-9_]+)"\s+limit="(\d+)"\s+template=`([^`]+)`}/g
        while ((match = queryRegex.exec(content)) !== null) {
            const [_, queryName, variable, limit, innerTemplate] = match
            if (!queryName) continue
            const inputName = `query:${queryName}`

            addOrMergeInput(inputName, {
                ...widgetInputDefault(),
                name: inputName,
                type: 'query',
                default_value: limit,
                options: { limit, variable, innerTemplate },
            })
        }

        // 2. {upload name="..."}, fallback to upload_X
        const uploadRegex = /\{upload(?:\s+name="([^"]*)")?}/g
        while ((match = uploadRegex.exec(content)) !== null) {
            const [_, name] = match
            const inputName = name || `upload_${inputsMap.size}`

            addOrMergeInput(inputName, {
                ...widgetInputDefault(),
                name: inputName,
                type: 'upload',
                default_value: '',
            })
        }

        // 3. {image name="..." source="..." class="..." alt="..."}
        const imageRegex =
            /\{image(?:\s+name="([^"]*)")?(?:\s+source="([^"]*)")?(?:\s+class="([^"]*)")?(?:\s+alt="([^"]*)")?}/g
        while ((match = imageRegex.exec(content)) !== null) {
            const [_, name, source, className = '', altText = 'Image'] = match
            const inputName = name || source || `image_${inputsMap.size}`
            const value = source?.startsWith('config:')
                ? source.replace('config:', '')
                : source || ''

            addOrMergeInput(inputName, {
                ...widgetInputDefault(),
                name: inputName,
                type: 'image',
                options: {
                    class: className,
                    alt: altText,
                    src: source?.startsWith('config:') ? 'config' : source ? 'upload' : 'unknown',
                    value,
                },
                default_value: value,
            })
        }

        // 4. {iSeo:key}
        const webInputRegex = /\{iSeo:([a-zA-Z0-9_.]+)}/g
        while ((match = webInputRegex.exec(content)) !== null) {
            const [_, name] = match
            const inputName = `iSeo:${name}`

            addOrMergeInput(inputName, {
                ...widgetInputDefault(),
                name: inputName,
                type: 'iSeo',
                default_value: name,
            })
        }

        // 5. {list:name variable="$x" template=`...`}
        const listRegex =
            /\{list:([a-zA-Z0-9_.]+)\s+variable="(\$[a-zA-Z0-9_]+)"\s+template=`([^`]+)`}/g
        while ((match = listRegex.exec(content)) !== null) {
            const [_, listName, variable, childTemplate] = match
            const inputName = `list_${listName}`

            addOrMergeInput(inputName, {
                ...widgetInputDefault(),
                name: inputName,
                type: 'list',
                default_value: [],
                options: {
                    variable,
                    template: childTemplate,
                },
            })
        }

        // 6. {normalInput}, skip already detected special inputs
        const normalRegex = /\{([a-zA-Z0-9_]+)}/g
        while ((match = normalRegex.exec(content)) !== null) {
            const inputName = match[1]
            if (
                inputsMap.has(inputName) || // Already added
                inputName.startsWith('query:') ||
                inputName.startsWith('iSeo:') ||
                inputName.startsWith('upload_') ||
                inputName.startsWith('image_')
            )
                continue

            addOrMergeInput(inputName, {
                ...widgetInputDefault(),
                name: inputName,
                type: 'text',
            })
        }
    })

    // ✅ Final output
    return Array.from(inputsMap.values())
}

// Get default language id by current language from cookie `iSpa-app-locale-xxxxxx`
export const getDefaultLanguageId = (languages = []) => {
    const locale = cookie.get(COOKIE_KEY_LOCALE, defaultLocale)

    const lang = languages.find((item) => item.path === locale)

    return lang ? lang.id : null
}

//  Convert number to string, example: 1000 -> 1k, 1000000 -> 1m
export const numberToString = (number = 0, fraction = 1) => {
    if (number >= 1000000000) {
        return (number / 1000000000).toFixed(fraction).replace(/\.0$/, '') + 'b'
    }
    if (number >= 1000000) {
        return (number / 1000000).toFixed(fraction).replace(/\.0$/, '') + 'm'
    }
    if (number >= 1000) {
        return (number / 1000).toFixed(fraction).replace(/\.0$/, '') + 'k'
    }

    return number
}

//  Get variant name
export const getVariantName = (variant = {}) => {
    const { sku = '', first_option = '', second_option = '' } = variant

    return (
        sku +
        (first_option ? ' - ' + first_option : '') +
        (second_option ? ' - ' + second_option : '')
    )
}

//  Get value by key of settings
export const getSettingValue = (settings = [], key = '', defaultValue = null) => {
    // Support both plain arrays and API responses shaped as { data: [...] }
    const settingList = Array.isArray(settings)
        ? settings
        : Array.isArray(settings?.data)
        ? settings.data
        : Array.isArray(settings?.value)
        ? settings.value
        : []

    if (!settingList.length) {
        return defaultValue
    }

    const setting = settingList.find((item) => item.setting_key === key || item.name === key)

    if (!setting) {
        return defaultValue
    }

    const { type = null, value = null } = setting

    if (['number', 'int', 'integer'].includes(type)) {
        const parsed = Number(value)
        return Number.isNaN(parsed) ? defaultValue : parsed
    }

    return value ?? defaultValue
}

//  Remove left trailing slash
export const removeLeftTrailingSlash = (str) => {
    return str.replace(/^\/+/g, '')
}

//  Init setting list
export const initSettingList = (list = [], listGroup = [], languages = []) => {
    const returnedList = {}
    const languagesIds = new Set(languages.map((item) => item.id)) // Use Set for faster lookup
    const filteredList = list.filter((item) => listGroup.includes(item.group_name))

    // Helper function to add missing language entries
    const addMissingLanguages = (group, parentGroup, title, item, defaultItem) => {
        const languageSection = parentGroup
            ? returnedList[group][parentGroup].languages
            : returnedList[group].unGroup.languages
        const existingLanguages = new Set(languageSection[title].items.map((i) => i.language_id))

        languagesIds.forEach((langId) => {
            if (!existingLanguages.has(langId)) {
                languageSection[title].items.push({
                    ...defaultItem,
                    language_id: langId,
                    setting_key: `${group}.${item.name}.${langId}`,
                })
            }
        })
    }

    filteredList.forEach((item) => {
        const { group_name = 'unGroup', input_setting = {} } = item
        const {
            parent_group = null,
            title = null,
            description = null,
            required,
            priority = 0,
        } = input_setting

        if (!returnedList[group_name]) {
            returnedList[group_name] = { unGroup: { languages: {}, list: [] } }
        }

        const targetGroup = parent_group
            ? (returnedList[group_name][parent_group] ||= {
                  languages: {},
                  list: [],
              })
            : returnedList[group_name].unGroup

        if (item.language_id > 0) {
            if (!targetGroup.languages[title]) {
                targetGroup.languages[title] = { items: [], description, required, priority }
            }
            targetGroup.languages[title].items.push(item)
            addMissingLanguages(group_name, parent_group, title, item, {
                id: null,
                value: null,
                group_name,
                input_setting,
                name: item.name,
            })
        } else {
            targetGroup.list.push(item)
        }
    })

    return returnedList
}

/** Handle name of key in translate */
export const translateNameHandle = (name) => {
    return name.replace('attributes.', 'a.').replace('between.', 'bw.')
}

//  Replace laravel backend key to vue key
export const strReplace = (val = '') => {
    const replaced = {
        ':attribute': '{a}',
        ':date': '{d}',
        ':values': '{vs}',
        ':other': '{o}',
        ':max': '{max}',
        ':min': '{min}',
        ':value': '{v}',
        ':digits': '{dg}',
        ':format': '{f}',
        ':size': '{s}',
        ':number': '{n}',
    }

    Object.keys(replaced).forEach((key) => {
        val = val.replace(key, replaced[key])
    })

    return val
}

//  Make first letter uppercase
export const ucfirst = (string) => {
    return string.charAt(0).toUpperCase() + string.slice(1)
}

//  Get last item of array
export const getLastItem = (arr) => {
    if (!Array.isArray(arr)) {
        return arr
    }

    return arr[arr.length - 1]
}
//  Get nav text by route name
export const getNavText = (nav = [], routeName = '', last = false) => {
    let text = []
    nav.forEach((item) => {
        if (!item.children) {
            if (item.name && item.name === routeName) {
                text = item.text
            }
        } else {
            item.children.forEach((child) => {
                if (child.name && child.name === routeName) {
                    text = child.text
                }
            })
        }
    })

    //  if last, return last item on text
    if (last) {
        return getLastItem(text)
    }

    return text
}

//  Get content status
export const getContentStatus = (status = '') => {
    return CONTENT_STATUSES[status] || ''
}
//  Get routing type for management
export const getRouteType = (routeName, type = 'type') => {
    let types = '',
        ids = '',
        columnLabels = []
    switch (routeName) {
        case 'place-comment':
        case 'place-content':
            types = 'Place'
            ids = 'placeId'
            columnLabels = ['nav.a.places']
            break
        case 'place-items-comment':
        case 'place-items-content':
            types = 'Item'
            ids = 'itemId'
            columnLabels = ['nav.a.items']
            break
        case 'product-comment':
            types = 'Product'
            ids = 'productId'
            columnLabels = ['nav.a.products']
            break
        case 'post-comment':
            types = 'Post'
            ids = 'postId'
            columnLabels = ['nav.a.posts']
            break
    }

    switch (type) {
        case 'type':
            return types
        case 'ids':
            return ids
        case 'columnLabels':
            return columnLabels
        default:
            return ''
    }
}

//  Content status color
export const contentStatusColor = (status = '') => {
    let color = 'default'
    switch (status) {
        case 'content_on_queue_translation':
            color = 'orange'
            break
        case 'content_translated':
            color = 'pink'
            break
        case 'content_moderated':
            color = 'purple'
            break
        case 'content_rejected':
            color = '#1e293b'
            break
        case 'content_pending_translation':
            color = 'warning'
            break
        case 'content_published':
            color = 'green'
            break
    }

    return color
}

/**
 * Tag colour for an order, payment or fulfillment status key. Stored keys are prefixed enumerates
 * (`order_*`, `payment_*`, `fulfillment_*`); the bare legacy words stay for older rows and other screens.
 * @param {string} status Raw status key.
 * @returns {string} Ant Design tag colour.
 */
export const orderStatusColor = (status = '') => {
    if (!status) {
        return 'default'
    }

    const normalized = status.toString().toLowerCase()

    if (
        ['order_pending', 'order_draft', 'order_closed', 'pending', 'draft', 'open'].includes(
            normalized
        )
    ) {
        return 'default'
    }

    if (
        [
            'order_awaiting_payment',
            'payment_unpaid',
            'awaiting_payment',
            'unpaid',
            'pending_payment',
        ].includes(normalized)
    ) {
        return 'pink'
    }

    if (['payment_authorized', 'authorized'].includes(normalized)) {
        return 'cyan'
    }

    if (
        [
            'processing',
            'order_processing',
            'order_in_progress',
            'in_progress',
            'fulfillment_unfulfilled',
            'fulfillment_partially_fulfilled',
            'partial',
            'awaiting_fulfillment',
        ].includes(normalized)
    ) {
        return 'gold'
    }

    if (
        [
            'content_moderated',
            'order_partially_paid',
            'payment_partially_paid',
            'partially_paid',
            'order_partially_refunded',
            'payment_partially_refunded',
            'partially_refunded',
            'partial_refund',
            'fulfillment_partially_returned',
        ].includes(normalized)
    ) {
        return 'purple'
    }

    if (['content_rejected', 'rejected'].includes(normalized)) {
        return '#1e293b'
    }

    if (['content_pending_translation', 'on_hold', 'hold'].includes(normalized)) {
        return 'warning'
    }

    if (
        [
            'order_paid',
            'order_completed',
            'order_delivered',
            'payment_paid',
            'paid',
            'fulfillment_delivered',
            'fulfillment_fulfilled',
            'fulfillment_shipped',
            'fulfillment_out_for_delivery',
            'complete',
            'completed',
        ].includes(normalized)
    ) {
        return 'green'
    }

    if (
        [
            'order_cancelled',
            'order_canceled',
            'payment_refunded',
            'payment_failed',
            'payment_voided',
            'fulfillment_returned',
            'canceled',
            'cancelled',
            'order_refunded',
            'refunded',
            'failed',
            'voided',
        ].includes(normalized)
    ) {
        return 'red'
    }

    return 'default'
}

//  Loading icon
export const loadingSpinner = () => {
    return h('img', {
        src: Spinner,
        style: {
            width: '32px',
            height: '32px',
        },
        spin: true,
        class: 'animate-spin',
    })
}

//  update only some field
//  Init data before send, the data only has some field deeper, we only update it into form.value, not replace
export const objectAssignOnlyByKeyHasValue = (form, data = {}) => {
    Object.keys(data).forEach((key) => {
        if (
            typeof form[key] === 'object' &&
            data[key] !== null &&
            data[key] !== undefined &&
            typeof data[key] === 'object'
        ) {
            form[key] = objectAssignOnlyByKeyHasValue(form[key], data[key])
        } else {
            form[key] = data[key]
        }
    })

    return form
}
export const getDefaultLanguage = (data) => {
    let languageId = 0
    if (data.data && data.data.length > 0) {
        data.data.forEach((e, index) => {
            //  Set default lang
            if (index === 0) {
                languageId = e.id
            }
            //  If language turn for web and default web
            if (e.for_web && e.default_web) {
                languageId = e.id
                return languageId
            }
        })
    }

    return languageId
}

//  Process to download Image from URL
export const downloadImage = async (qrUrl, name) => {
    await ajax
        .get(qrUrl, {
            responseType: 'arraybuffer',
        })
        .then((res) => {
            const url = window.URL.createObjectURL(new Blob([res.data]))
            const link = document.createElement('a')
            link.href = url
            link.setAttribute('download', name + '.png')
            document.body.appendChild(link)
            link.click()
        })
}

export const convertURLtoFile = async (url) => {
    const response = await fetch(url)
    const data = await response.blob()
    const ext = url.split('.').pop().split('?')[0]
    const filename = url.split('/').pop()
    const metadata = { type: `image/${ext}` }
    return new File([data], filename, metadata)
}

export const getLanguageName = (data = [], id = 0) => {
    const lang = data.find((e) => e.id === id)
    if (!lang || !lang.name) {
        return 'N/A'
    }
    return lang.name
}

/**
 * Check user permission
 * @param module string
 * @param resource string
 * @param action string
 *
 * @author malayvuong
 * @since 8.0.0 - 2026-02-01, 10:04 ITC
 */
export const perm = (module, resource, action) => {
    if (!module) throw new Error('perm(module, ...) requires module')

    if (module && !resource && !action) {
        return { t: 'module', m: module }
    }

    if (module && resource && !action) {
        return { t: 'resource', m: module, r: resource }
    }

    if (module && resource && action) {
        return { t: 'action', m: module, r: resource, a: action }
    }

    throw new Error('perm() invalid arguments')
}

/**
 * Check if user has permission
 *
 * @param {string|number|Array|Object} ruleSpec - Permission key, ID, key array, or structured spec
 * @returns {boolean}
 *
 * @deprecated Use can() from @/hooks/useCan for new code
 * @see @/hooks/useCan.js for the new permission API with better features
 *
 * @example
 * // Legacy patterns (still supported):
 * rule('user.viewAny')        // string key
 * rule(['user.view', 'user.edit'])  // array of keys
 *
 * // New patterns (recommended):
 * import { can, crud, canAny } from '@/hooks/useCan'
 * can('education', 'edu_semester', 'view')
 * can(crud('education', 'edu_semester'))
 *
 * @author malayvuong
 * @since 1.0.0 - 2021-12-14, 11:43
 */
export const rule = (ruleSpec = '') => {
    // Empty rule always passes
    if (isEmpty(ruleSpec)) return true

    // Check by type
    if (typeof ruleSpec === 'string') {
        return can(String(ruleSpec).trim())
    }

    if (Array.isArray(ruleSpec)) {
        return ruleSpec.some((key) => can(String(key).trim()))
    }

    if (isObject(ruleSpec)) {
        return can(ruleSpec)
    }

    const { value: account } = useStorage(CACHE_KEY_ACCOUNT_DATA, {})

    // No account or roles = no permission
    if (isEmpty(account) || isEmpty(account.roles) || !Array.isArray(account.roles)) {
        return false
    }

    const permissionIds = new Set()

    for (const role of account.roles) {
        for (const perm of role.permissions || []) {
            permissionIds.add(perm.id)
        }
    }

    if (typeof ruleSpec === 'number') {
        return permissionIds.has(ruleSpec)
    }

    return false
}

/** Make copy */
// utils/iCopy.js
// Robust copy-to-clipboard with secure-context + legacy fallbacks
export const iCopy = async (val) => {
    try {
        // SSR / non-DOM guard
        if (typeof window === 'undefined' || typeof document === 'undefined') {
            throw new Error('No DOM available')
        }

        const text = String(val ?? '')

        // 1) Modern Clipboard API (requires https or localhost + user gesture)
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text)
            iAlert({
                message: `<b>${text}</b> has been copied!`,
                type: 'success',
            })
            return true
        }

        // 2) Fallback: hidden textarea + execCommand('copy')
        const ta = document.createElement('textarea')
        ta.value = text
        ta.setAttribute('readonly', '')
        ta.style.position = 'fixed'
        ta.style.top = '-9999px'
        ta.style.opacity = '0'
        document.body.appendChild(ta)

        // preserve current selection
        const selection = document.getSelection?.()
        const range = selection && selection.rangeCount ? selection.getRangeAt(0) : null

        ta.select()
        ta.setSelectionRange(0, ta.value.length) // iOS support

        const ok = document.execCommand('copy') // returns boolean
        document.body.removeChild(ta)

        // restore selection
        if (range && selection) {
            selection.removeAllRanges()
            selection.addRange(range)
        }

        if (!ok) throw new Error('execCommand failed')

        iAlert({
            message: `<b>${text}</b> has been copied!`,
            type: 'success',
        })
        return true
    } catch (err) {
        console.error('[iCopy] Copy failed:', err)
        iAlert('Cannot copy', true)
        return false
    }
}

/** Translator for the default notification titles, registered once by the app shell (keeps this module free of the i18n instance). */
let alertTitleTranslator = null

/**
 * Register the function that resolves a translation key to text (or a falsy value when the key is missing).
 *
 * @param {((key: string) => string | null | undefined) | null} translator Key resolver.
 * @returns {void}
 */
export const setAlertTitleTranslator = (translator) => {
    alertTitleTranslator = typeof translator === 'function' ? translator : null
}

const DEFAULT_ALERT_TITLES = {
    error: 'Error',
    warning: 'Warning',
    info: 'Info',
    success: 'Success',
}

/**
 * Default title of a notification type, translated when a translator is registered.
 *
 * @param {'error'|'warning'|'info'|'success'} type Notification type.
 * @returns {string} Translated title, or the English default.
 */
const defaultAlertTitle = (type) => {
    const translated = alertTitleTranslator?.(`text.title.${type}`)
    return translated || DEFAULT_ALERT_TITLES[type]
}

/** Notification Alert */
export const iAlert = (val = [], error = false) => {
    // An error the ajax client already reported to the person is not reported twice.
    if (isObject(val) && val.reported === true) return
    const notify = {
        type: 'success',
        message: '',
        dangerouslyUseHTMLString: true,
        position: 'bottom-left',
    }
    if (isArray(val)) {
        notify.message = val[0]
        if (val[1]) notify.title = val[1]
        if (val[2]) notify.type = val[2]
    } else if (isObject(val)) {
        if (val.title) {
            notify.title = val.title
        }
        if (val.message) {
            if (isArray(val.message) || isObject(val.message)) {
                let str = ''
                if (isArray(val.message)) {
                    val.message.forEach((e) => {
                        if (isArray(e)) {
                            e.forEach((f) => {
                                str += `<p>${f}</p>`
                            })
                        } else {
                            str += `<p>${e}</p>`
                        }
                    })
                } else {
                    // eslint-disable-next-line no-unused-vars
                    for (const [key, value] of Object.entries(val.message)) {
                        if (isArray(value)) {
                            value.forEach((f) => {
                                str += `<p>${f}</p>`
                            })
                        } else {
                            str += `<p>${value}</p>`
                        }
                    }
                }
                notify.message = str
            } else {
                notify.message = val.message
            }
        }
        if (val.type) {
            notify.type = val.type
        }
        if (val.error) {
            notify.type = 'error'
        }
    } else {
        notify.message = val
    }
    if (isObject(val) && val.title) {
        notify.title = val.title
    }
    //  Over write error status
    if (error) {
        notify.type = 'error'
    }

    const message = typeof notify.message === 'string' ? notify.message : String(notify.message ?? '')

    const notificationType = ['error', 'warning', 'info', 'success'].includes(notify.type)
        ? notify.type
        : 'success'
    const notificationTitle = notify.title || defaultAlertTitle(notificationType)

    notification[notificationType]({
        message: notificationTitle,
        description: () => h('div', { innerHTML: message }),
        placement: 'bottomLeft',
    })

    //  If token unverified -> go to sign in page
    if (message.includes('Unauthenticated') || message.includes('Unauthorized')) {
        window.location.href = basePath + 'auth/sign-in'
    }
}

export const classNames = (...classes) => {
    return classes.filter(Boolean).join(' ')
}

/** Number format */
export const numberFormat = (val, format = '0,000.0') => {
    return numeral(val).format('"' + format + '"')
}

/** Date format */
export const dateFormat = (val = null, format = 'YYYY-MM-DD') => {
    if (!val) {
        val = new Date()
    }

    return dayjs(val).tz('Asia/Ho_Chi_Minh').format(format)
}

/**
 * Get class color for badge by permission key
 *
 * @param val
 * @returns {string}
 *
 * @author malayvuong
 * @since 1.0.0
 */
export const badgeColor = (val) => {
    let className = 'default'
    switch (val) {
        case 'viewAny':
        case 'create':
        case 'view':
            className = 'success'
            break
        case 'edit':
        case 'update':
            className = 'primary'
            break
        case 'delete':
        case 'forceDelete':
        case 'banned':
            className = 'danger'
            break
        case 'restore':
            className = 'info'
            break
        case 'username':
        case 'email':
        case 'mobile':
            className = 'warning'
            break

        default:
            break
    }
    return className
}

/**
 * Get class color for badge by place type
 *
 * @param val
 * @returns {string}
 *
 * @author malayvuong
 * @since 7.160.1175
 */
export const placeTypeBadgeColor = (val) => {
    let className = 'default'
    switch (val) {
        case 'showroom':
        case 'store':
            className = 'success'
            break
        case 'agency':
        case 'warehouse':
            className = 'primary'
            break
        case 'factory':
        case 'gallery':
        case 'museum':
            className = 'danger'
            break
        case 'office':
            className = 'info'
            break
        case 'other':
        case 'repair-center':
        case 'service-point':
        case 'service-center':
            className = 'warning'
            break

        default:
            break
    }
    return className
}

/**
 * Get class color for badge by place type
 *
 * @param val
 * @returns {string}
 *
 * @author malayvuong
 * @since 7.160.1175
 */
export const postTypeBadgeColor = (val) => {
    let className = 'default'
    switch (val) {
        case 'post':
        case 'video':
            className = 'success'
            break
        case 'document':
            className = 'primary'
            break
        case 'audio':
        case 'page':
            className = 'red'
            break
        case 'admission':
            className = 'info'
            break
        case 'notification':
        case 'events':
            className = 'warning'
            break

        default:
            break
    }
    return className
}

export default {
    iAlert,
    numberFormat,
    dateFormat,
    badgeColor,
    iCopy,
    getLanguageName,
}

//  Init query params before send request
export const initFilter = (filterFields = {}, query = {}) => {
    const newQuery = { ...filterFields, ...query }

    if (isEmpty(query.keywords) && isEmpty(filterFields.keywords)) {
        delete newQuery.keywords
    }

    if (!query.status) {
        newQuery.status = filterFields.status
    }
    if (query.categoryId && query.categoryId.length > 0) {
        const newCategoryId = []
        if (query.categoryId.indexOf(',') >= 0) {
            query.categoryId.split(',').forEach((item) => {
                if (item !== 'all') {
                    newCategoryId.push(parseInt(item))
                }
            })
            newQuery.categoryId = newCategoryId
        } else {
            newQuery.categoryId = query.categoryId !== 'all' ? [parseInt(query.categoryId)] : []
        }
    }
    if (!query.userId) {
        newQuery.userId = filterFields.userId
    } else {
        newQuery.userId = parseInt(query.userId)
    }
    if (!query.placeId) {
        newQuery.placeId = filterFields.placeId
    } else {
        newQuery.placeId = parseInt(query.placeId)
    }
    if (!query.storageId) {
        newQuery.storageId = filterFields.storageId
    } else {
        newQuery.storageId = parseInt(query.storageId)
    }
    if (!query.languageId) {
        newQuery.languageId = filterFields.languageId
    }
    if (!query.page) {
        newQuery.page = filterFields.page
    }
    if (!query.pageSize) {
        newQuery.pageSize = filterFields.pageSize
    }
    if (
        !query.dateRange &&
        filterFields.dateRange &&
        filterFields.dateRange[0] !== null &&
        filterFields.dateRange[1] !== null
    ) {
        newQuery.dateRange = filterFields.dateRange
    }
    // delete all undefined value or null value
    Object.keys(newQuery).forEach((key) => {
        if (newQuery[key] === undefined || newQuery[key] === null) {
            delete newQuery[key]
        }
    })

    return newQuery
}

export const allowedQueryByType = (newQuery = {}, type = 'user') => {
    const defaultAllowedKey = ['page', 'pageSize', 'keywords']
    let allowedKey = []
    //  Process to delete key by type (keep allowed key)
    if (type === 'user') {
        allowedKey = [...defaultAllowedKey, 'status']
    } else if (type === 'post') {
        allowedKey = [...defaultAllowedKey, 'status', 'categoryId']
    } else if (type === 'product') {
        allowedKey = [...defaultAllowedKey, 'status', 'categoryId', 'brandId']
    } else if (type === 'place') {
        allowedKey = [...defaultAllowedKey, 'status', 'categoryId', 'userId']
    } else if (type === 'content') {
        allowedKey = [...defaultAllowedKey, 'status', 'confirmed', 'placeId', 'itemId', 'target']
    }

    Object.keys(newQuery).forEach((key) => {
        if (!allowedKey.includes(key) || isEmpty(newQuery[key])) {
            delete newQuery[key]
        }
    })

    return newQuery
}

/**
 * Make string to slug
 *
 * @author vuongnm
 * @since 7.x - 2023-09-18, 09:02 ICT
 */
export const makeSlug = (str) => {
    if (str) {
        return str
            .toLowerCase()
            .replace(/ /g, '-')
            .replace(/[^\w-]+/g, '')
    }

    return ''
}
