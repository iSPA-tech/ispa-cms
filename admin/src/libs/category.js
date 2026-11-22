/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const getCategories = async (params) => {
    return await ajax.get('category', { params: params }).then((res) => res.data || {})
}

export const getCategory = async (id, edit = false) => {
    return await ajax.get(`category/${id}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const storeCategory = async (data = {}) => {
    return await ajax.post('category', data).then((res) => res.data || {})
}

export const updateCategory = async (id, data = {}) => {
    return await ajax.put(`category/${id}`, data).then((res) => res.data || {})
}

export const deleteCategory = async (id) => {
    return await ajax.delete(`category/${id}`).then((res) => res.data || {})
}
