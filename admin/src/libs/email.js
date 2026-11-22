/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

export const emailCounter = async () => {
    return await ajax.get('email/counter').then((res) => res.data || {})
}

export const searchFilterEmails = async (params = {}) => {
    return await ajax.get('email/search', { params: params }).then((res) => res.data || {})
}

export const getEmails = async (params = {}) => {
    return await ajax.get('email', { params: params }).then((res) => res.data || {})
}

export const getEmail = async (id, edit = false) => {
    return await ajax.get(`email/${id}${edit ? '/edit' : ''}`).then((res) => res.data || {})
}
export const storeEmail = async (params = {}) => {
    return await ajax.post('email', params).then((res) => res.data || {})
}

export const replyEmail = async (id, params = {}) => {
    return await ajax.put(`email/${id}/reply`, params).then((res) => res.data || {})
}
export const deleteEmail = async (id) => {
    return await ajax.delete(`email/${id}`).then((res) => res.data || {})
}

export const markEmailsAsSpam = async (uuids = [], blockDomain = false) => {
    return await ajax.put('email/spam', { uuids, block_domain: blockDomain }).then((res) => res.data || {})
}

export const getEmailTemplates = async (params = {}) => {
    return await ajax.get('email-template', { params: params }).then((res) => res.data || {})
}
export const storeEmailTemplate = async (params = {}) => {
    return await ajax.post('email-template', params).then((res) => res.data || {})
}
export const updateEmailTemplate = async (id = 0, params = {}) => {
    return await ajax.put(`email-template/${id}`, params).then((res) => res.data || {})
}
