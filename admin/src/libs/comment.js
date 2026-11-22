/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const getRelationComment = async (params) => {
    const path = `${params.type}/${params.id}/comment`

    return await ajax.get(path).then((res) => res.data || {})
}

export const getComments = async (params) => {
    return await ajax.get('/comment', { params: params }).then((res) => res.data || {})
}

export const getComment = async (id) => {
    return await ajax.get(`/comment/${id}`).then((res) => res.data || {})
}

export const storeComment = async (data = {}) => {
    return await ajax.post('/comment', data).then((res) => res.data || {})
}

export const updateComment = async (id, data = {}) => {
    return await ajax.put(`/comment/${id}`, data).then((res) => res.data || {})
}

export const restoreComment = async (id, data = {}) => {
    return await ajax.put(`/comment/${id}/restore`, data).then((res) => res.data || {})
}

export const deleteComment = async (id, data = {}) => {
    return await ajax.delete(`/comment/${id}`, data).then((res) => res.data || {})
}
