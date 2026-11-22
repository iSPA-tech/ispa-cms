/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const moduleName = 'province'

export const searchFilterProvinces = async (params) => {
    return await ajax.get(moduleName + '/search', { params: params }).then((res) => res.data || {})
}

export const getProvinces = async (params) => {
    // try {
    return await ajax.get(moduleName, { params: params }).then((res) => res.data || {})
}

export const getProvince = async (id, edit = false) => {
    return await ajax.get(`${moduleName}/${id}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const storeProvince = async (data = {}) => {
    return await ajax.post(moduleName, data).then((res) => res.data || {})
}

export const updateProvince = async (id, data = {}) => {
    return await ajax.put(`${moduleName}/${id}`, data).then((res) => res.data || {})
}

export const deleteProvince = async (id) => {
    return await ajax.delete(`${moduleName}/${id}`).then((res) => res.data || {})
}

export const restoreProvince = async (id) => {
    return await ajax.put(`${moduleName}/${id}/restore`).then((res) => res.data || {})
}
