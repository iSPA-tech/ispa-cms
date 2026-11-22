/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterTranslates = async (params) => {
    return await ajax.get('translate/search', { params: params }).then((res) => res.data || {})
}

export const getTranslates = async (params) => {
    // try {
    return await ajax.get('translate', { params: params }).then((res) => res.data || {})
}

export const getTranslate = async (id, edit = false) => {
    return await ajax.get(`translate/${id}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const storeTranslate = async (data = {}) => {
    return await ajax.post('translate', data).then((res) => res.data || {})
}

export const updateTranslate = async (id, data = {}) => {
    return await ajax.put(`translate/${id}`, data).then((res) => res.data || {})
}

export const deleteTranslate = async (id) => {
    return await ajax.delete(`translate/${id}`).then((res) => res.data || {})
}

export const restoreTranslate = async (id) => {
    return await ajax.put(`translate/${id}/restore`).then((res) => res.data || {})
}
