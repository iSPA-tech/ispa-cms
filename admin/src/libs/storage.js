/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterStorages = async (params) => {
    return await ajax.get('/storage/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getStorages = async (params) => {
    // try {
    return await ajax.get('/storage', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getStorage = async (id, edit = false) => {
    return await ajax.get(`/storage/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const deleteStorage = async (id) => {
    return await ajax.delete(`/storage/${id}`).then((res) => {
        return res.data.data || []
    })
}

export const restoreStorage = async (id) => {
    return await ajax.put(`/storage/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}
