/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterFields = async (params) => {
    return await ajax.get('/field/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getFields = async (params) => {
    // try {
    return await ajax.get('/field', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getField = async (id, edit = false) => {
    return await ajax.get(`/field/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const storeField = async (data = {}) => {
    return await ajax.post('/field', data).then((res) => res.data || [])
}

export const updateField = async (id, data = {}) => {
    return await ajax.put(`/field/${id}`, data).then((res) => res.data || [])
}

export const deleteField = async (id) => {
    return await ajax.delete(`/field/${id}`).then((res) => {
        return res.data.data || []
    })
}

export const restoreField = async (id) => {
    return await ajax.put(`/field/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}
