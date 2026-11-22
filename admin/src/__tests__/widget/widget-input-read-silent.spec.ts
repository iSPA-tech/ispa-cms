import { describe, expect, it, vi } from 'vitest'
import ajax from '@/ajax'
import { getInputByWidget } from '@/libs/widget'

describe('getInputByWidget', () => {
    it('passes the request options on, so a caller can keep a refused read quiet', async () => {
        const get = vi.spyOn(ajax, 'get').mockResolvedValue({ data: { status: 'success', data: [] } } as any)

        await getInputByWidget(3, { silent: true })

        expect(get).toHaveBeenCalledWith('widget/3/inputs', { silent: true })
    })
})
