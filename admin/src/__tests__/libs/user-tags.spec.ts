import { beforeEach, describe, expect, it, vi } from 'vitest'

const ajax = vi.hoisted(() => ({ delete: vi.fn(), get: vi.fn(), post: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajax }))
import { attachUserTags, createUserTag, deleteUserTag, detachUserTags, getUsersByFilter, searchUserTags } from '@/libs/user-tags'

describe('user tag API client', () => {
    beforeEach(() => vi.clearAllMocks())

    it('searches tags and creates one by name', async () => {
        ajax.get.mockResolvedValue({ data: { data: { items: [{ uuid: 't-1', name: 'Triết', users_count: 3 }] } } })
        ajax.post.mockResolvedValue({ data: { data: { uuid: 't-2', name: 'Thần', users_count: 0 } } })
        expect(await searchUserTags('triet')).toEqual([{ uuid: 't-1', name: 'Triết', users_count: 3 }])
        expect(ajax.get).toHaveBeenCalledWith('user-tags', { params: { q: 'triet' } })
        expect((await createUserTag('Thần')).uuid).toBe('t-2')
        expect(ajax.post).toHaveBeenCalledWith('user-tags', { name: 'Thần' })
        await deleteUserTag('t-2')
        expect(ajax.delete).toHaveBeenCalledWith('user-tags/t-2')
    })

    it('reshapes users/filter like the plain Users list', async () => {
        ajax.get.mockResolvedValue({ data: { data: { items: [{ uuid: 'u-1' }], total: 40, page: 2, per_page: 20 } } })
        const res = await getUsersByFilter({ tags: ['t-1'], page: 2 })
        expect(ajax.get).toHaveBeenCalledWith('users/filter', { params: { tags: ['t-1'], page: 2 } })
        expect(res).toEqual({ data: { data: [{ uuid: 'u-1' }], total: 40, currentPage: 2, perPage: 20 } })
    })

    it('posts attach and detach bodies as they are', async () => {
        ajax.post.mockResolvedValue({ data: { status: 'success', data: { affected: 2 } } })
        expect((await attachUserTags({ tag_uuids: ['t-1'], user_uuids: ['u-1'] })).data.affected).toBe(2)
        await detachUserTags({ tag_uuids: ['t-1'], filter: { q: 'lan' } })
        expect(ajax.post).toHaveBeenNthCalledWith(1, 'users/tags/attach', { tag_uuids: ['t-1'], user_uuids: ['u-1'] })
        expect(ajax.post).toHaveBeenNthCalledWith(2, 'users/tags/detach', { tag_uuids: ['t-1'], filter: { q: 'lan' } })
    })
})
