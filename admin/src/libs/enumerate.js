/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterEnumerates = async (params) => {
    return await ajax.get('/enumerate/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getEnumerates = async (params) => {
    // try {
    return await ajax.get('/enumerate', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getEnumerate = async (id, edit = false) => {
    return await ajax.get(`/enumerate/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const storeEnumerate = async (data = {}) => {
    return await ajax.post('enumerate', data).then((res) => {
        return res.data || {}
    })
}

export const updateEnumerate = async (id = 0, data = {}) => {
    return await ajax.put(`enumerate/${id}`, data).then((res) => {
        return res.data || {}
    })
}

export const deleteEnumerate = async (id) => {
    return await ajax.delete(`/enumerate/${id}`).then((res) => {
        return res.data.data || []
    })
}

export const restoreEnumerate = async (id) => {
    return await ajax.put(`/enumerate/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}

export const getEnumerateTypes = async (params) => {
    // try {
    return await ajax.get('/enumerate-type', { params: params }).then((res) => {
        return res.data.data || []
    })
}
