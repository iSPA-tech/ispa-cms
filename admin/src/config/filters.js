/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

const baseFilter = {
    page: 1,
    pageSize: 20,
    keywords: '',
    status: 'all',
}

export const metaAttributeFilter = {
    page: 1,
    pageSize: 20,
    keywords: '',
}
export const brandFilter = {
    ...baseFilter,
    status: 'all',
}

export const contactFilter = {
    ...baseFilter,
    status: 'all',
    type: '',
    sourcePrefix: '',
}

export const bannerFilter = {
    ...baseFilter,
    status: 'all',
}
export const translateFilter = {
    ...baseFilter,
}
export const userFilter = {
    ...baseFilter,
    status: 'user_active',
}
export const itemFilter = {
    ...baseFilter,
    placeId: null,
    establishedBy: null,
    storageId: null,
}
export const productFilter = {
    ...baseFilter,
    status: 'all',
    categoryId: null,
}
export const productBundleFilter = {
    ...baseFilter,
    categoryId: null,
}
export const postFilter = {
    ...baseFilter,
    status: 'all',
    categoryId: null,
}
export const placeFilter = {
    ...baseFilter,
    status: 'all',
    categoryId: null,
    userId: null,
}
export const galleryFilter = {
    ...baseFilter,
    status: 'all',
    categoryId: null,
}

export const contentPlaceFilter = {
    ...baseFilter,
    status: 'all',
    placeId: null,
}

export const contentItemFilter = {
    ...baseFilter,
    status: 'all',
    itemId: null,
}

export const commentPlaceFilter = {
    ...baseFilter,
    status: 'all',
    placeId: null,
}

export const commentItemFilter = {
    ...baseFilter,
    status: 'all',
    itemId: null,
}
export const commentPostFilter = {
    ...baseFilter,
    status: 'all',
    itemId: null,
}

export const orderFilter = {
    ...baseFilter,
    status: 'all',
}
