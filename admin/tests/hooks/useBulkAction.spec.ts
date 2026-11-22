/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { describe, expect, it } from 'vitest'
import {
    buildPreviewContent,
    isRateLimitedError,
    resolvePreviewCount,
} from '@/hooks/useBulkAction'

describe('bulk action helpers', () => {
    it('resolves count from affected_count', () => {
        const count = resolvePreviewCount({ data: { affected_count: 3 } }, [1, 2])

        expect(count).toBe(3)
    })

    it('resolves count from eligible_ids', () => {
        const count = resolvePreviewCount({ data: { eligible_ids: [10, 11, 12, 13] } }, [1])

        expect(count).toBe(4)
    })

    it('falls back to selected ids length', () => {
        const count = resolvePreviewCount({ data: {} }, [1, 2, 3, 4, 5])

        expect(count).toBe(5)
    })

    it('builds preview content with pluralization and suffix', () => {
        expect(buildPreviewContent(1)).toBe('This action will affect 1 item. Continue?')
        expect(buildPreviewContent(2)).toBe('This action will affect 2 items. Continue?')
        expect(buildPreviewContent(2, 'This action cannot be undone.')).toBe(
            'This action will affect 2 items. Continue? This action cannot be undone.'
        )
    })

    it('detects rate limited errors', () => {
        expect(isRateLimitedError({ response: { status: 429 } })).toBe(true)
        expect(isRateLimitedError({ response: { status: 400 } })).toBe(false)
        expect(isRateLimitedError(null)).toBe(false)
    })
})
