import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import StudentReport from '@/components/student-report.vue'

const requests = vi.hoisted(() => ({
    v2: vi.fn(() => Promise.resolve({ data: { status: 'success', data: {} } })),
    v4: vi.fn(() => Promise.resolve({ data: { status: 'success', data: {} } })),
}))

vi.mock('@/ajax', () => ({ default: { get: requests.v2 } }))
vi.mock('@/ajax-v4', () => ({ default: { get: requests.v4 } }))

describe('StudentReport', () => {
    it('loads the existing V2 student dashboard endpoint', async () => {
        mount(StudentReport, {
            props: { canView: true },
            global: {
                stubs: { 'a-spin': { template: '<div><slot /></div>' } },
            },
        })

        await flushPromises()

        expect(requests.v2).toHaveBeenCalledWith('user/dashboard/student')
        expect(requests.v4).not.toHaveBeenCalled()
    })
})
