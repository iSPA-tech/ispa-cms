/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const searchFilterContacts = async (params) => {
    return await ajax.get('/contact/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getContacts = async (params) => {
    return await ajax.get('/contact', { params: params }).then((res) => {
        if (res.data.data) {
            return res.data.data
        }
        return []
    })
}

export const getContactDashboard = async (params = {}) => {
    return await ajax.get('/contact/dashboard', { params }).then((res) => {
        return res.data.data || {}
    })
}

export const getContact = async (id, edit = false) => {
    return await ajax.get(`/contact/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || {}
    })
}

export const storeContact = async (data = {}) => {
    return await ajax.post('/contact', data)
}

export const updateContact = async (id, data = {}) => {
    return await ajax.put(`/contact/${id}`, data)
}

export const deleteContact = async (id) => {
    return await ajax.delete(`/contact/${id}`)
}

export const importContacts = async (formData) => {
    return await ajax
        .post('/contact/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        .then((res) => res.data || {})
}

export const exportContacts = async (params = {}) => {
    return await ajax.get('/contact/export', {
        params,
        responseType: 'blob',
    })
}
