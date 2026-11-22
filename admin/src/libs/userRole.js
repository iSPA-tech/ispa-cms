/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterUserRoles = async (params) => {
    return await ajax.get('/role/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getUserRoles = async (params) => {
    // try {
    return await ajax.get('/role', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getUserRole = async (id, edit = false) => {
    return await ajax.get(`/role/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const deleteUserRole = async (id) => {
    return await ajax.delete(`/role/${id}`).then((res) => {
        return res.data.data || []
    })
}

export const restoreUserRole = async (id) => {
    return await ajax.put(`/role/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}
