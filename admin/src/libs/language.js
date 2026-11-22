/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'
const basePath = 'language'

export const searchFilterLanguages = async (params) => {
    return await ajax.get(basePath + '/search', { params: params }).then((res) => res.data || {})
}

/**
 * Fetch the flag image files a language can use.
 *
 * @returns {Promise<Array<{file: string, url: string}>>}
 */
export const getLanguageFlags = async () => {
    return await ajax.get(basePath + '/flags').then((res) => res.data?.data || [])
}

export const getLanguages = async (params) => {
    // try {
    return await ajax.get(basePath, { params: params }).then((res) => res.data || {})
}

export const getLanguage = async (id, edit = false) => {
    return await ajax
        .get(`${basePath}/${id}${edit ? '/edit' : ''}`)
        .then((res) => res.data?.data || {})
}

export const switchLanguage = async (id, col, value) => {
    return await ajax
        .put(`${basePath}/${id}/switch/${col}`, { col, value })
        .then((res) => res.data || {})
}

export const deleteLanguage = async (id) => {
    return await ajax.delete(`${basePath}/${id}`).then((res) => res.data || {})
}

export const restoreLanguage = async (id) => {
    return await ajax.put(`${basePath}/${id}/restore`).then((res) => res.data || {})
}
