import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import dayjs from 'dayjs'
import ajax from '@/ajax'
import CalendarPage from '../../../../cms/Content/admin/pages/calendar/calendar.vue'
import { renderWithApp } from '@/test-utils/renderWithApp'

const listDaysResponse = {
    data: {
        status: 'success',
        data: {
            month: 'June 2026',
            grid: 5,
            list: [
                {
                    date: '2026-06-10',
                    day: '10',
                    now: true,
                    current_month: true,
                    events: [],
                },
            ],
        },
    },
}

const enumerateResponse = {
    data: {
        status: 'success',
        data: [
            {
                type: 'status',
                enum_key: 'status_draft',
                name: 'Draft',
                name_alias: 'Draft',
            },
            {
                type: 'status',
                enum_key: 'status_published',
                name: 'Published',
                name_alias: 'Published',
            },
            {
                type: 'content_status',
                enum_key: 'content_pending',
                name: 'Pending',
                name_alias: 'Pending',
            },
        ],
    },
}

const languageSearchResponse = {
    data: {
        status: 'success',
        data: [
            {
                id: 7,
                name: 'English',
                path: 'en',
            },
        ],
    },
}

const renderCalendar = () => {
    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
        },
    })

    return renderWithApp(CalendarPage, {
        globalMocks: {
            $isMobile: () => false,
        },
        globalStubs: {
            'a-drawer': { template: '<section><slot /><slot name="footer" /></section>' },
            'i-icon': { template: '<span />' },
            'i-switch': { template: '<input type="checkbox" />' },
        },
        global: {
            plugins: [[VueQueryPlugin, { queryClient }]],
        },
    })
}

describe('calendar create', () => {
    beforeEach(() => {
        vi.mocked(ajax.get).mockReset()
        vi.mocked(ajax.post).mockReset()
        vi.mocked(ajax.put).mockReset()

        vi.mocked(ajax.get).mockImplementation((url: string) => {
            if (url.startsWith('utilities/list-days')) {
                return Promise.resolve(listDaysResponse)
            }
            if (url === '/enumerate') {
                return Promise.resolve(enumerateResponse)
            }
            if (url === 'language/search') {
                return Promise.resolve(languageSearchResponse)
            }

            return Promise.reject(new Error(`Unexpected GET ${url}`))
        })
        vi.mocked(ajax.post).mockResolvedValue({ data: { status: 'success' } })
    })

    it('submits a normalized calendar payload without loading the removed cache-tag endpoint', async () => {
        const { wrapper } = await renderCalendar()
        await flushPromises()

        expect(wrapper.vm.statusOptions.map((item: { enum_key: string }) => item.enum_key)).toEqual([
            'status_draft',
            'status_published',
        ])
        expect(wrapper.vm.statusSelectOptions.map((item: { value: string }) => item.value)).toEqual([
            'status_draft',
            'status_published',
        ])
        expect(wrapper.vm.statusSelectOptions).not.toContainEqual(expect.objectContaining({ value: 0 }))

        wrapper.vm.formRef = { validate: vi.fn().mockResolvedValue(undefined), resetFields: vi.fn() }
        wrapper.vm.form = {
            id: 0,
            name: 'Council meeting',
            url: null,
            date_from: dayjs('2026-06-10 08:15:00'),
            date_to: dayjs('2026-06-10 09:45:00'),
            language_id: 7,
            is_repeat: false,
            repeat_type: '0',
            status: 'status_draft',
            is_all_day: false,
        }

        await wrapper.vm.formSubmit()

        expect(ajax.get).not.toHaveBeenCalledWith('cache-tag', expect.anything())
        expect(ajax.post).toHaveBeenCalledWith('calendar', {
            id: 0,
            name: 'Council meeting',
            url: null,
            date_from: '2026-06-10 08:15:00',
            date_to: '2026-06-10 09:45:00',
            language_id: 7,
            is_repeat: false,
            repeat_type: 0,
            status: 'status_draft',
            is_all_day: false,
        })
    })

    it('falls back to published status when the form has no selected enum', async () => {
        const { wrapper } = await renderCalendar()
        await flushPromises()

        wrapper.vm.formRef = { validate: vi.fn().mockResolvedValue(undefined), resetFields: vi.fn() }
        wrapper.vm.form = {
            id: 0,
            name: 'Fallback status event',
            url: null,
            date_from: dayjs('2026-06-10 08:15:00'),
            date_to: dayjs('2026-06-10 09:45:00'),
            language_id: 7,
            is_repeat: false,
            repeat_type: '0',
            status: 0,
            is_all_day: false,
        }

        await wrapper.vm.formSubmit()

        expect(ajax.post).toHaveBeenCalledWith(
            'calendar',
            expect.objectContaining({
                status: 'status_published',
            })
        )
    })

    it('uses the shared language select component for new events', async () => {
        const { wrapper } = await renderCalendar()
        await flushPromises()

        wrapper.vm.formRef = { validate: vi.fn().mockResolvedValue(undefined), resetFields: vi.fn() }

        wrapper.vm.createEvent()
        await flushPromises()

        expect(wrapper.findComponent({ name: 'SelectInputLanguage' }).exists()).toBe(true)
        expect(ajax.get).toHaveBeenCalledWith('language/search', {
            params: {
                pageSize: 100,
                select: 'id,name,path',
            },
        })
        expect(wrapper.vm.form.language_id).toBe(7)
        expect(wrapper.vm.form.language_id).not.toBe(0)
    })
})
