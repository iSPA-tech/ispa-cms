/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterPermissions = async (params) => {
    return await ajax.get('/permission/search', { params: params }).then((res) => {
        if (res.data.data) {
            return res.data.data
        }
        return []
    })
}

const normalizePermissionData = (body) => {
    if (Array.isArray(body)) {
        return body
    }

    if (!body || typeof body !== 'object') {
        return []
    }

    if (Array.isArray(body.data)) {
        return body.data
    }

    if (body.data && Array.isArray(body.data.data)) {
        return body.data.data
    }

    if (Array.isArray(body.permissions)) {
        return body.permissions
    }

    return []
}

export const getPermissions = async (params) => {
    return await ajax.get('/permission', { params: params }).then((res) => {
        return normalizePermissionData(res.data)
    })
}

export const getPermission = async (id, edit = false) => {
    return await ajax.get(`/permission/${id}${edit ? '/edit' : ''}`).then((res) => {
        if (res.data.data) {
            return res.data.data
        }
        return []
    })
}

export const deletePermission = async (id) => {
    return await ajax.delete(`/permission/${id}`).then((res) => {
        return res.data
    })
}

export const restorePermission = async (id) => {
    return await ajax.put(`/permission/${id}/restore`).then((res) => {
        return res.data
    })
}

export const getRoles = async (params) => {
    return await ajax.get('/role', { params: params }).then((res) => res.data.data || {})
}

export const getRole = async (id, edit = false) => {
    return await ajax.get(`/role/${id}${edit ? '/edit' : ''}`).then((res) => {
        if (res.data.data) {
            return res.data.data
        }
        return []
    })
}
