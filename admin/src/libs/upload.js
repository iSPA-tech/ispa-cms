/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterUploads = async (params) => {
    return await ajax.get('upload/search', { params: params }).then((res) => {
        return res.data || []
    })
}

// `endpoint` lets a module point the gallery at its own list route; every other caller keeps `upload`.
export const getUploads = async (params, endpoint = 'upload') => {
    return await ajax.get(endpoint, { params: params }).then((res) => res.data || {})
}

export const getUploadDashboard = async (params = {}) => {
    return await ajax.get('upload/dashboard', { params }).then((res) => res.data || {})
}

export const getUpload = async (id, edit = false) => {
    return await ajax.get(`upload/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data || []
    })
}

export const storeUpload = async (data = {}) => {
    return await ajax.post('upload', data).then((res) => res.data || {})
}

export const updateUpload = async (id, data = {}) => {
    return await ajax.put(`upload/${id}`, data).then((res) => res.data || {})
}

export const updateUploadPassword = async (id, data = {}) => {
    return await ajax.put(`upload/${id}/password`, data).then((res) => res.data || {})
}

export const deleteUpload = async (id) => {
    return await ajax.delete(`upload/${id}`).then((res) => {
        return res.data || []
    })
}

export const restoreUpload = async (id) => {
    return await ajax.put(`upload/${id}/restore`).then((res) => {
        return res.data || []
    })
}
