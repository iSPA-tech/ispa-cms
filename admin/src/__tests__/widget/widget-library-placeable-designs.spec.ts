/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// Widget library API client: which designs a shared area accepts is asked from the server with the existing
// `placeable=1` list rule, once per session.

import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.hoisted(() => vi.fn())
vi.mock('@/ajax', () => ({ default: { get } }))
vi.mock('@/libs', () => ({ getWidgetPreview: vi.fn() }))

import { forgetPlaceableDesigns, listPlaceableDesigns } from '../../../../cms/Core/admin/widget-library-api'

const page = (rows: unknown[], lastPage = 1) => ({ data: { status: 'success', data: { data: rows, last_page: lastPage } } })

beforeEach(() => {
    get.mockReset()
    forgetPlaceableDesigns()
})

describe('listPlaceableDesigns', () => {
    it('reads every page of the placeable list once and answers later calls from the cache', async () => {
        get.mockResolvedValueOnce(page([{ id: 10, template: [{ id: 101 }] }], 2)).mockResolvedValueOnce(
            page([{ id: 11, template: [{ id: 110 }, { id: 111 }] }], 2)
        )

        const designs = await listPlaceableDesigns()
        expect([...designs.get(10)!]).toEqual([101])
        expect([...designs.get(11)!]).toEqual([110, 111])
        expect(designs.has(12)).toBe(false)
        expect(get).toHaveBeenCalledTimes(2)
        expect(get.mock.calls[0]).toEqual(['widget/admin-list', { params: { placeable: 1, pageSize: 100, page: 1 }, silent: true }])

        await listPlaceableDesigns()
        expect(get).toHaveBeenCalledTimes(2)

        // After a design save the next dialog asks again.
        forgetPlaceableDesigns()
        get.mockResolvedValueOnce(page([]))
        await listPlaceableDesigns()
        expect(get).toHaveBeenCalledTimes(3)
    })

    it('does not cache a refused read', async () => {
        get.mockResolvedValueOnce({ data: { status: 'error' } })
        await expect(listPlaceableDesigns()).rejects.toThrow()

        get.mockResolvedValueOnce(page([{ id: 10, template: [{ id: 100 }] }]))
        expect((await listPlaceableDesigns()).get(10)?.has(100)).toBe(true)
    })
})
