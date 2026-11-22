/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Pick a listing text in the admin language, falling back to the other language when it is empty.
 *
 * @param {object|undefined} content Per-language listing content, `{vi: {...}, en: {...}}`.
 * @param {string} locale Admin language (`vi` or `en`).
 * @param {string} field Field name, for example `display_name` or `summary`.
 * @returns {string} The text, or an empty string.
 */
export const listingText = (content, locale, field) => {
    const own = content?.[locale]?.[field]
    if (typeof own === 'string' && own.trim() !== '') return own
    const other = content?.[locale === 'vi' ? 'en' : 'vi']?.[field]
    return typeof other === 'string' ? other : ''
}
