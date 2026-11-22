/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

const module = 'setting'

export const searchFilterSettings = async (params) => {
    return await ajax.get(module + '/search', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getSettings = async (params) => {
    // try {
    return await ajax.get(module, { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getSetting = async (id, edit = false) => {
    return await ajax.get(`${module}/${id}${edit ? '/edit' : ''}`).then((res) => {
        return res.data.data || []
    })
}

export const getHomepageSetting = async () => {
    return await ajax.get(`${module}/homepage`).then((res) => {
        return res.data || {}
    })
}

export const storeSetting = async (data = {}) => {
    return await ajax.post(module, data).then((res) => res.data || {})
}

export const updateSetting = async (id, data = {}) => {
    return await ajax.put(`${module}/${id}`, data).then((res) => res.data || {})
}

export const updateHomepageSetting = async (data = {}) => {
    return await ajax.put(`${module}/homepage`, data).then((res) => res.data || {})
}

export const deleteSetting = async (id) => {
    return await ajax.delete(`${module}/${id}`).then((res) => {
        return res.data.data || []
    })
}

export const restoreSetting = async (id) => {
    return await ajax.put(`${module}/${id}/restore`).then((res) => {
        return res.data.data || []
    })
}

export const getSchedulers = async (params) => {
    // try {
    return await ajax.get('/scheduler', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getWorkflows = async (params) => {
    return await ajax.get('workflows', { params: params }).then((res) => {
        return res.data.data || []
    })
}

export const getWorkflow = async (id) => {
    return await ajax.get(`workflows/${id}/edit`).then((res) => res.data.data || {})
}

export const getFunctionsList = async () => {
    return await ajax.get('workflows/function-list', { params: { compact: 1 } }).then((res) => {
        return res.data.data || []
    })
}

export const getModelSchema = async (model) => {
    return await ajax.get('workflow/schema', { params: { model } }).then((res) => res.data || {})
}

export const getRelationSchema = async (model, relation) => {
    return await ajax
        .get('workflow/schema/relation', { params: { model, relation } })
        .then((res) => res.data || {})
}

export const storeWorkflow = async (data = {}) => {
    return await ajax.post('workflows', data).then((res) => res.data || {})
}

export const updateWorkflow = async (id, data = {}) => {
    return await ajax.put(`workflows/${id}`, data).then((res) => res.data || {})
}

export const deleteWorkflow = async (id) => {
    return await ajax.delete(`workflows/${id}`).then((res) => res.data || {})
}
