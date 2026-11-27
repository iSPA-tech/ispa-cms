import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/ajax', () => ({ default: http }))

import { resolveReviewers, searchReviewers } from '@/services/workflowApi'

describe('reviewer lookup of the workflow builder', () => {
    beforeEach(() => http.get.mockReset())

    it('searches the people who may approve by name, quietly', async () => {
        http.get.mockResolvedValue({ data: { data: [{ uuid: 'u-1', name: 'Nguyễn An', can_review: true, email: 'x' }] } })
        expect(await searchReviewers(' An ')).toEqual([{ uuid: 'u-1', name: 'Nguyễn An', detail: '', can_review: true }])
        expect(http.get).toHaveBeenCalledWith('workflows/reviewers', { params: { q: 'An' }, silent: true })
        await searchReviewers('')
        expect(http.get).toHaveBeenLastCalledWith('workflows/reviewers', { params: {}, silent: true })
    })

    it('resolves saved uuids in one request and asks nothing for an empty list', async () => {
        http.get.mockResolvedValue({ data: { data: [{ uuid: 'u-2', name: 'Trần Bình', can_review: false }] } })
        expect(await resolveReviewers(['u-2', 'gone', ''])).toEqual([{ uuid: 'u-2', name: 'Trần Bình', detail: '', can_review: false }])
        expect(http.get).toHaveBeenCalledWith('workflows/reviewers', { params: { uuids: ['u-2', 'gone'] }, silent: true })
        http.get.mockClear()
        expect(await resolveReviewers([])).toEqual([])
        expect(http.get).not.toHaveBeenCalled()
    })
})
