/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { describe, expect, it } from 'vitest'
import {
    areFilterValuesEqual,
    buildSchemaDefaults,
    defaultParseQuery,
    defaultSerializeQuery,
    normalizeFilterValues,
} from '@/components/filter-utils'

describe('filter utils', () => {
    it('builds defaults from schema', () => {
        const defaults = buildSchemaDefaults([
            { key: 'keywords', type: 'search', defaultValue: '' },
            { key: 'status', type: 'select', default: 'all' },
            { key: 'categoryId', type: 'cascader' },
            { key: 'note', type: 'text', value: 'hello' },
        ])

        expect(defaults).toEqual({
            keywords: '',
            status: 'all',
            categoryId: [],
            note: 'hello',
        })
    })

    it('parses and serializes query values with array keys', () => {
        const parsed = defaultParseQuery(
            { keywords: 'test', status: 'all', categoryId: '1,2,all', ignored: 'x' },
            { allowedKeys: ['keywords', 'status', 'categoryId'], arrayKeys: ['categoryId'] }
        )

        expect(parsed).toEqual({
            keywords: 'test',
            status: 'all',
            categoryId: ['1', '2'],
        })

        const serialized = defaultSerializeQuery(
            { keywords: 'test', status: 'all', categoryId: ['1', '2'], empty: '' },
            { allowedKeys: ['keywords', 'status', 'categoryId', 'empty'], arrayKeys: ['categoryId'] }
        )

        expect(serialized).toEqual({
            keywords: 'test',
            status: 'all',
            categoryId: '1,2',
        })
    })

    it('normalizes filters and compares values safely', () => {
        const normalized = normalizeFilterValues(
            { page: '1', status: 'all', empty: '' },
            { page: 1, status: 'all', empty: '' }
        )

        expect(normalized).toEqual({ page: 1, status: 'all' })
        expect(areFilterValuesEqual('1', 1)).toBe(true)
        expect(areFilterValuesEqual(null, 'all')).toBe(true)
        expect(areFilterValuesEqual(['1'], ['1'])).toBe(true)
    })
})
