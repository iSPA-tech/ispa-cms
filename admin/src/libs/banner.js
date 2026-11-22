/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const getBanners = async (params) => {
    return await ajax.get('banner', { params: params }).then((res) => res.data || {})
}

export const getBanner = async (id, edit = false) => {
    return await ajax.get(`banner/${id}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const storeBanner = async (data = {}) => {
    return await ajax.post('banner', data).then((res) => res.data || {})
}

export const updateBanner = async (id, data = {}) => {
    return await ajax.put(`banner/${id}`, data).then((res) => res.data || {})
}

export const deleteBanner = async (id) => {
    return await ajax.delete(`banner/${id}`).then((res) => res.data || {})
}
