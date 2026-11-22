/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Shared presentation for post rows: one hue per content type (icon tile, a-tag, distribution bar)
// and one semantic tone per publishing status. Pages pass the raw type/status; colours live only here.

const TYPE_STYLES = {
    post: {
        icon: 'newspaper',
        tag: 'blue',
        tile: 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
        bar: 'bg-blue-500',
    },
    page: {
        icon: 'page-layout-body',
        tag: 'purple',
        tile: 'bg-purple-50 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
        bar: 'bg-purple-500',
    },
    document: {
        icon: 'file-document-outline',
        tag: 'cyan',
        tile: 'bg-cyan-50 text-cyan-800 dark:bg-cyan-500/15 dark:text-cyan-300',
        bar: 'bg-cyan-500',
    },
    events: {
        icon: 'calendar-month-outline',
        tag: 'geekblue',
        tile: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
        bar: 'bg-indigo-500',
    },
    video: {
        icon: 'video',
        tag: 'magenta',
        tile: 'bg-pink-50 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300',
        bar: 'bg-pink-500',
    },
    audio: {
        icon: 'podcast',
        tag: 'volcano',
        tile: 'bg-orange-50 text-orange-800 dark:bg-orange-500/15 dark:text-orange-300',
        bar: 'bg-orange-500',
    },
    image: {
        icon: 'image',
        tag: 'magenta',
        tile: 'bg-pink-50 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300',
        bar: 'bg-pink-400',
    },
    notification: {
        icon: 'bell-badge',
        tag: 'lime',
        tile: 'bg-lime-50 text-lime-800 dark:bg-lime-500/15 dark:text-lime-300',
        bar: 'bg-lime-500',
    },
    admission: {
        icon: 'school-outline',
        tag: 'gold',
        tile: 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        bar: 'bg-amber-500',
    },
}

const DEFAULT_TYPE_STYLE = {
    icon: 'text-box-multiple-outline',
    tag: 'default',
    tile: 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300',
    bar: 'bg-slate-400',
}

// a-badge `status` values: green = live, gold = waiting for review, grey = not public.
const STATUS_BADGES = {
    status_published: 'success',
    status_pending: 'warning',
    status_draft: 'default',
    status_archived: 'default',
}

/**
 * Icon, tag colour, tinted tile classes and bar colour for a post type.
 *
 * @param {string|null|undefined} type Raw post type (`post`, `page`, ...).
 * @returns {{icon: string, tag: string, tile: string, bar: string}}
 */
export const postTypeStyle = (type) => TYPE_STYLES[String(type || '').toLowerCase()] || DEFAULT_TYPE_STYLE

/**
 * a-badge status tone for a post status enum key.
 *
 * @param {string|null|undefined} status Raw status enum key (`status_published`, ...).
 * @returns {'success'|'warning'|'default'}
 */
export const postStatusBadge = (status) => STATUS_BADGES[String(status || '').toLowerCase()] || 'default'
