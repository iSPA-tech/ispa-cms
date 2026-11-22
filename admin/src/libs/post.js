/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterPosts = async (params) => {
    return await ajax.get('post/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getPosts = async (params) => {
    // try {
    return await ajax.get('post', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getPostDashboard = async (params = {}) => {
    return await ajax
        .get('dashboard/post', {
            params,
        })
        .then((res) => {
            return res.data.data || {}
        })
}

export const getPost = async (uuid, edit = false) => {
    return await ajax.get(`post/${uuid}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}

export const deletePost = async (uuid) => {
    return await ajax.delete(`post/${uuid}`).then((res) => {
        return res.data.data || []
    })
}

export const restorePost = async (uuid) => {
    return await ajax.put(`post/${uuid}/restore`).then((res) => {
        return res.data.data || []
    })
}

export const quickPost = async (uuid, status) => {
    return await ajax.put(`post/${uuid}/quick`, { status: status }).then((res) => res.data || {})
}
