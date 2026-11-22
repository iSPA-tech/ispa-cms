import { beforeEach, describe, expect, it, vi } from 'vitest'

/*
 * a rejection that the ajax client already reported is not reported a second time when a page passes the same
 * error object to iAlert ("Request failed with status code 403" after the friendly demo message).
 */
const notification = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn(), info: vi.fn() }))
vi.mock('ant-design-vue', async (importOriginal) => ({ ...(await importOriginal<object>()), notification }))

const { iAlert } = await import('@/mixins/utils')

describe('iAlert and an error the ajax client reported', () => {
    beforeEach(() => {
        Object.values(notification).forEach((fn) => fn.mockReset())
    })

    it('shows nothing for an error marked as reported', () => {
        iAlert({ message: 'Request failed with status code 403', reported: true }, true)
        expect(notification.error).not.toHaveBeenCalled()
    })

    it('still shows every other error, string or object', () => {
        iAlert({ message: 'Request failed with status code 500' }, true)
        iAlert('Plain text problem', true)
        expect(notification.error).toHaveBeenCalledTimes(2)
    })
})
