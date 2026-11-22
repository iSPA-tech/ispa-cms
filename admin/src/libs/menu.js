/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'
import { iAlert } from '@/mixins'

export const searchFilterMenus = async (params) => {
    return await ajax.get('/menu/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getMenus = async (params) => {
    // try {
    return await ajax.get('/menu', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getMenu = async (id, edit = false) => {
    return await ajax.get(`/menu/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const storeMenu = async (data = {}) => {
    return await ajax.post('/menu', data).then((res) => res.data || {})
}

export const updateMenu = async (id, data = {}) => {
    return await ajax.put(`/menu/${id}`, data).then((res) => res.data || {})
}

export const deleteMenu = async (id) => {
    return await ajax.delete(`/menu/${id}`).then((res) => res.data)
}

export const restoreMenu = async (id) => {
    return await ajax.put(`/menu/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}

export const deleteMenuItem = async (id, itemId) => {
    return await ajax.delete(`/menu/${id}/items/${itemId}`).then((res) => res.data)
}

export const deleteMenuItemByIndex = async (
    callback,
    menuId,
    items = [],
    index = 0,
    itemId = 0
) => {
    const item = items[index]

    if (item) {
        if (itemId > 0) {
            await deleteMenuItem(menuId, itemId).then((res) => {
                if (res?.status === 'success') {
                    callback()
                }
                if (res?.message) {
                    iAlert(res.message)
                }
            })
        } else {
            items.splice(index, 1)
        }
    }

    return items
}
