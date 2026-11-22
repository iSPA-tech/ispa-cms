/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterMetaClasses = async (params) => {
    return await ajax.get('/meta-class/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getMetaClasses = async (params) => {
    // try {
    const { data } = await ajax.get('/meta-class', { params: params }).then((res) => {
        return res.data.data || []
    })
    //  Make sure result is the data array
    if (data.data) {
        return data.data
    }
    return data
}

export const getMetaClass = async (id, edit = false) => {
    return await ajax.get(`/meta-class/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const deleteMetaClass = async (id) => {
    return await ajax.delete(`/meta-class/${id}`).then((res) => {
        return res.data.data || []
    })
}

export const restoreMetaClass = async (id) => {
    return await ajax.put(`/meta-class/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}
