/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterMetaAttributes = async (params) => {
    return await ajax.get('meta-attribute/search', { params: params }).then((res) => res.data || {})
}

export const getMetaAttributes = async (params) => {
    // try {
    return await ajax.get('meta-attribute', { params: params }).then((res) => res.data || {})
}

export const getMetaAttribute = async (id, edit = false) => {
    return await ajax
        .get(`meta-attribute/${id}${edit ? '/edit' : ''}`)
        .then((res) => res.data || {})
}

export const getValueAttribute = async (id) => {
    return await ajax.get(`meta-attribute/${id}/options`).then((res) => res.data || {})
}

export const storeMetaAttribute = async (data = {}) => {
    return await ajax.post(`meta-attribute`, data).then((res) => res.data || {})
}

export const updateMetaAttribute = async (id, data = {}) => {
    return await ajax.put(`meta-attribute/${id}`, data).then((res) => res.data || {})
}

export const deleteMetaAttribute = async (id) => {
    return await ajax.delete(`meta-attribute/${id}`).then((res) => res.data || {})
}

export const restoreMetaAttribute = async (id) => {
    return await ajax.put(`meta-attribute/${id}/restore`).then((res) => res.data.data || {})
}
