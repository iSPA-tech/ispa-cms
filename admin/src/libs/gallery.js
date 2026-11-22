/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const moduleName = 'gallery'

export const getGalleries = async (params) => {
    return await ajax.get(moduleName, { params: params }).then((res) => res.data || {})
}

export const getGallery = async (id, edit = false) => {
    return await ajax.get(`${moduleName}/${id}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const storeGallery = async (data = {}) => {
    return await ajax.post(moduleName, data).then((res) => res.data || {})
}

export const updateGallery = async (id, data = {}) => {
    return await ajax.put(`${moduleName}/${id}`, data).then((res) => res.data || {})
}

export const quickGallery = async (uid, status) => {
    return await ajax.put(`gallery/${uid}/quick`, { status: status }).then((res) => res.data || {})
}
