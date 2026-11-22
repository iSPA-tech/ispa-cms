import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/ajax', () => ({ default: { get: vi.fn() } }))

import ajax from '@/ajax'
import { getNotifications } from '@/libs/notifications'

describe('notifications client', () => {
    beforeEach(() => vi.clearAllMocks())

    it('sends page and unread filter and returns the total', async () => {
        ;(ajax.get as any).mockResolvedValue({ data: { data: { data: [{ id: 'a' }], meta: { unread_count: 2, total: 41 } } } })
        const result = await getNotifications({ unread: true, perPage: 20, page: 3 })
        expect(ajax.get).toHaveBeenCalledWith('notifications', expect.objectContaining({ params: { per_page: 20, page: 3, unread: 1 }, silent: true }))
        expect(result).toEqual({ items: [{ id: 'a' }], unreadCount: 2, total: 41 })
    })

    it('omits page 1 and the unread flag by default', async () => {
        ;(ajax.get as any).mockResolvedValue({ data: { data: { data: [], meta: {} } } })
        await getNotifications()
        expect(ajax.get).toHaveBeenCalledWith('notifications', expect.objectContaining({ params: { per_page: 20 } }))
    })
})
