/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import ajax from '@/ajax'

/** GET user-tags?q= -> [{ uuid, name, users_count }] (needs user.viewAny). */
export const searchUserTags = async (q = '') =>
    await ajax.get('user-tags', { params: q ? { q } : {} }).then((res) => res.data?.data?.items || [])

/** POST user-tags { name } -> the tag; the existing one when the name matches (Triết = Triet). */
export const createUserTag = async (name) => await ajax.post('user-tags', { name }).then((res) => res.data?.data)

/** DELETE user-tags/{uuid} (hard delete, needs user.update). */
export const deleteUserTag = async (uuid) => {
    const res = await ajax.delete(`user-tags/${uuid}`)
    return res?.data || {}
}

/** GET users/filter, reshaped like the plain Users list: { data: { data: rows, total, currentPage, perPage } }. */
export const getUsersByFilter = async (params = {}) =>
    await ajax.get('users/filter', { params }).then((res) => {
        const body = res.data?.data || {}
        return { data: { data: body.items || [], total: body.total || 0, currentPage: body.page || 1, perPage: body.per_page || 20 } }
    })

/** POST users/tags/attach { tag_uuids, user_uuids | filter } -> { data: { affected } }. */
export const attachUserTags = async (payload) => await ajax.post('users/tags/attach', payload).then((res) => res.data || {})

/** POST users/tags/detach { tag_uuids, user_uuids | filter } -> { data: { affected } }. */
export const detachUserTags = async (payload) => await ajax.post('users/tags/detach', payload).then((res) => res.data || {})
