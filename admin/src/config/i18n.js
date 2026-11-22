/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { createI18n } from 'vue-i18n'
import ajax from '../ajax'
import { appVersion, buildVersion, apiUrl, defaultLocale } from './base'
import { nextTick } from 'vue'
import { cookie } from '@/config/cookies'
import { useStorage } from '@vueuse/core'
import { CACHE_KEY_MESSAGES_LOCALE, COOKIE_KEY_LOCALE } from '@/config/constants'
import {
    ADMIN_LOCALE_GROUPS,
    I18N_MESSAGES_SCHEMA_VERSION,
    humanizeI18nKey,
    localeManifestStamp,
    mergeLocaleMessageTree,
    normalizeLocaleManifest,
    normalizeLocaleShardPayload,
    withFallbackMessages,
} from './i18n-message-utils'
// Gate screen wording built from the Core sidecar (see admin-i18n-fallback.js); the loaded pack overrides it.
import fallbackMessages from 'virtual:admin-i18n-fallback'

// The cached packs are tied to the admin build as well as the schema: a new deploy ships new keys (and new
// pages that use them), so a browser must not keep serving the tree it cached from the previous build.
// buildVersion changes on every build, including runtime rebuilds after a module install where appVersion
// stays the same.
const MESSAGE_CACHE_SCHEMA = `${I18N_MESSAGES_SCHEMA_VERSION}@${appVersion}+${buildVersion}`

const createMessageCacheState = () => ({
    __schema: MESSAGE_CACHE_SCHEMA,
})

const messages = useStorage(CACHE_KEY_MESSAGES_LOCALE, createMessageCacheState())

const ensureMessageCacheSchema = () => {
    if (!messages.value || messages.value.__schema !== MESSAGE_CACHE_SCHEMA) {
        messages.value = createMessageCacheState()
    }
}

// `r` mirrors I18N_MESSAGES_SCHEMA_VERSION: bumping the schema also changes
// the URL, so clients that HTTP-cached shards under the pre-ETag 7-day
// Cache-Control fetch fresh copies instead of serving week-old keys.
// `b` is the per-build id: every admin build (also a runtime rebuild with an
// unchanged app version) bypasses HTTP-cached shards and manifest.
const buildAdminLocaleUrl = (locale, fileName) =>
    apiUrl +
    `api/${appVersion}/i18n/adm/` +
    locale +
    '/' +
    fileName +
    `.json?r=${encodeURIComponent(I18N_MESSAGES_SCHEMA_VERSION)}&b=${encodeURIComponent(buildVersion)}`

// A pack request that fails must not raise error toasts: the screens that show before sign-in (the iSPA Hub connect
// screen) read the bundled fallback wording, and the console already gets the error.
const PACK_REQUEST_OPTIONS = { silent: true }

/**
 * The pack's shard list and content stamp. A manifest that cannot be read gives the legacy shard list and no stamp.
 * @returns {Promise<{files: string[], stamp: string}>}
 */
const loadAdminLocaleManifest = async (locale) => {
    try {
        const manifestPayload = await ajax
            .get(buildAdminLocaleUrl(locale, 'manifest'), PACK_REQUEST_OPTIONS)
            .then((res) => res.data)

        const allFiles = normalizeLocaleManifest(manifestPayload)

        // Only load groups that the admin dashboard actually uses
        return {
            files: allFiles.filter((f) => ADMIN_LOCALE_GROUPS.includes(f)),
            stamp: localeManifestStamp(manifestPayload),
        }
    } catch (error) {
        console.warn('Falling back to legacy admin locale file list.', error)
        return { files: [...ADMIN_LOCALE_GROUPS], stamp: '' }
    }
}

export const i18n = setupI18n({
    locale: cookie.get(COOKIE_KEY_LOCALE, defaultLocale),
    fallbackLocale: 'en',
})

export function setupI18n(options = { locale: cookie.get(COOKIE_KEY_LOCALE, defaultLocale) }) {
    const innerOption = {
        legacy: false,
        locale: cookie.get(COOKIE_KEY_LOCALE, defaultLocale),
        globalInjection: true,
        // Dynamic keys (enum/table-name suffixes) without a translation fall
        // back to a humanized tail instead of showing the raw key.
        missing: (locale, key) => humanizeI18nKey(key),
        missingWarn: false,
        fallbackWarn: false,
        // Real text for the gate screen before (or without) the pack.
        messages: fallbackMessages,
        ...options,
    }

    const i18n = createI18n(innerOption)
    setI18nLanguage(i18n, innerOption.locale).then(() => {})

    return i18n
}

export async function setI18nLanguage(i18n, locale) {
    //  Load locale messages before set locale in i18n
    await loadLocaleMessages(i18n, locale).then(() => {
        // @ts-ignore
        document.querySelector('html').setAttribute('lang', locale)
    })

    if (i18n.mode === 'legacy') {
        i18n.global.locale = locale
    } else {
        i18n.global.locale.value = locale
    }

    cookie.set(COOKIE_KEY_LOCALE, locale)
}

// Function declarations, not const arrows: the module loads the cookie locale on import (setupI18n above), before
// any const below it exists.

/**
 * Fetch every shard the manifest lists and merge them into one tree.
 * @returns {Promise<{messageJson: object, failed: number}>} `failed` counts shards that could not be loaded.
 */
async function fetchLocaleShards(locale, fileNames) {
    const messageJson = {}
    let failed = 0

    await Promise.all(
        fileNames.map(async (fileName) => {
            try {
                const url = buildAdminLocaleUrl(locale, fileName)
                const shardPayload = await ajax.get(url, PACK_REQUEST_OPTIONS).then((res) => res.data)

                mergeLocaleMessageTree(messageJson, normalizeLocaleShardPayload(fileName, shardPayload))
            } catch (error) {
                failed++
                console.error(`Error loading ${fileName}.json:`, error)
            }
        })
    )

    return { messageJson, failed }
}

/** Keep a locale's tree and the stamp of the pack it came from in localStorage. */
function storeLocaleMessages(locale, messageJson, stamp) {
    messages.value = {
        ...messages.value,
        __schema: MESSAGE_CACHE_SCHEMA,
        __stamps: { ...(messages.value.__stamps || {}), [locale]: stamp },
        [locale]: messageJson,
    }
}

/**
 * The cached tree belongs to this admin build, but the server can regenerate the pack under the same build (a deploy
 * that seeds new keys, a redeploy of the same version, a translation edited in the admin). Read the manifest in the
 * background and reload the pack when its stamp differs, so nobody has to clear the browser's storage. A manifest
 * without a stamp (older server), a failing manifest or a failing shard keeps the cached tree.
 */
async function revalidateLocaleMessages(i18n, locale) {
    const { files, stamp } = await loadAdminLocaleManifest(locale)
    if (!stamp || stamp === messages.value.__stamps?.[locale]) {
        return
    }

    const { messageJson, failed } = await fetchLocaleShards(locale, files)
    if (failed > 0 || Object.keys(messageJson).length === 0) {
        return
    }

    storeLocaleMessages(locale, messageJson, stamp)
    i18n.global.setLocaleMessage(locale, withFallbackMessages(fallbackMessages[locale], messageJson))
}

export async function loadLocaleMessages(i18n, locale) {
    ensureMessageCacheSchema()

    if (messages.value[locale] && Object.keys(messages.value[locale]).length >= 1) {
        // Show the cached tree at once; a newer pack replaces it when the check finishes.
        i18n.global.setLocaleMessage(
            locale,
            withFallbackMessages(fallbackMessages[locale], messages.value[locale])
        )
        revalidateLocaleMessages(i18n, locale).catch(() => {})
    } else {
        const { files, stamp } = await loadAdminLocaleManifest(locale)
        const { messageJson } = await fetchLocaleShards(locale, files)

        storeLocaleMessages(locale, messageJson, stamp)
        i18n.global.setLocaleMessage(locale, withFallbackMessages(fallbackMessages[locale], messageJson))
    }

    return nextTick()
}
