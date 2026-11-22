/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterUsers = async (params) => {
    return await ajax.get('user/search', { params: params }).then((res) => res.data || {})
}

export const getUsers = async (params) => {
    return await ajax.get('user', { params: params }).then((res) => res.data || {})
}

export const getUser = async (uuid, options = {}) => {
    let edit = false
    let params = {}

    if (typeof options === 'boolean') {
        edit = options
    } else if (options && typeof options === 'object') {
        edit = options.edit ?? false
        if (options.params) {
            params = options.params
        } else {
            const { edit: _editFlag, ...rest } = options
            params = rest
        }
    }

    const url = `user/${uuid}${edit ? '/edit' : ''}`

    return await ajax.get(url, { params }).then((res) => {
        return res.data || []
    })
}

export const getUserDashboard = async (params = {}) => {
    return await ajax.get('user/dashboard', { params }).then((res) => res.data || {})
}

export const storeUser = async (data = {}) => {
    return await ajax.post('user', data).then((res) => res.data || {})
}

export const updateUser = async (uuid, data = {}) => {
    return await ajax.put(`user/${uuid}`, data).then((res) => res.data || {})
}

export const updateUserPassword = async (uuid, data = {}) => {
    return await ajax.put(`user/${uuid}/password`, data).then((res) => res.data || {})
}

export const resendUserEmailVerification = async (uuid) => {
    return await ajax
        .post(`user/${uuid}/email-verification/resend`)
        .then((res) => res.data || {})
}

export const deleteUser = async (uuid) => {
    return await ajax.delete(`user/${uuid}`).then((res) => {
        return res.data || []
    })
}

export const restoreUser = async (uuid) => {
    return await ajax.put(`user/${uuid}/restore`).then((res) => {
        return res.data || []
    })
}

//  Account section
export const getAccount = async () => {
    return await ajax.get(`account/info`).then((res) => {
        return res.data || []
    })
}

export const signOutAccount = async () => {
    return await ajax.post('account/sign-out').then((res) => {
        return res.data || []
    })
}

export const signInAsUser = async (uuid = '') => {
    return await ajax.post('account/sign-in-as', { uuid }).then((res) => {
        return res.data || {}
    })
}
