/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterContents = async (params) => {
    return await ajax.get('/content/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getContents = async (params) => {
    return await ajax.get('/content', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getContent = async (id, edit = false) => {
    return await ajax.get(`content/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const storeContent = async (data = {}) => {
    return await ajax.post('/content', data).then((res) => res.data || [])
}

export const updateContent = async (id, data = {}) => {
    return await ajax.put(`content/${id}`, data).then((res) => res.data || [])
}

export const confirmContent = async (id, data = {}) => {
    return await ajax.put(`content/${id}/confirmation`, data)
}

export const translateContent = async (id, data = {}) => {
    return await ajax.put(`content/${id}/translate`, data).then((res) => res.data || {})
}

export const convertAudio = async (id, data = {}) => {
    return await ajax.put(`content/${id}/convert`, data)
}

export const deleteContent = async (id) => {
    return await ajax.delete(`content/${id}`).then((res) => {
        return res.data.data || []
    })
}

export const restoreContent = async (id) => {
    return await ajax.put(`content/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}
