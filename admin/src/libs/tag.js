/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'
const basePath = 'tag'

export const searchFilterTags = async (params) => {
    return await ajax
        .get(basePath + '/search', { params: params })
        .then((res) => res.data.data || {})
}

export const getTags = async (params) => {
    return await ajax.get(basePath, { params: params }).then((res) => res.data.data || {})
}

export const getTag = async (uuid, edit = false) => {
    return await ajax.get(`${basePath}/${uuid}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const storeTag = async (data = {}) => {
    return await ajax.post(basePath, data).then((res) => res.data || {})
}

export const updateTag = async (uuid, data = {}) => {
    return await ajax.put(`${basePath}/${uuid}`, data).then((res) => res.data || {})
}

export const deleteTag = async (uuid) => {
    return await ajax.delete(`${basePath}/${uuid}`).then((res) => res.data || {})
}
