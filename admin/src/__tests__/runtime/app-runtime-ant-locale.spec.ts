import { mount } from '@vue/test-utils'
import { ConfigProvider, DatePicker } from 'ant-design-vue'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { appRuntime } from '@/runtime'

// Ant pickers take month/weekday names from dayjs, keyed by the code of the active ant locale, so the
// dayjs 'vi' locale must be registered or the calendar renders English names next to Vietnamese labels.
const openCalendarText = async () => {
    const Host = defineComponent({
        setup: () => () =>
            h(
                ConfigProvider,
                { locale: appRuntime.antLocale.value },
                () => h(DatePicker, { open: true, value: dayjs('2026-11-01'), getPopupContainer: () => document.body })
            ),
    })
    const wrapper = mount(Host, { attachTo: document.body })
    await nextTick()
    const text = document.body.textContent ?? ''
    wrapper.unmount()
    return text
}

describe('admin Ant locale follows the admin language', () => {
    afterEach(() => {
        appRuntime.locale.value = 'en'
        document.body.innerHTML = ''
    })

    it('registers the dayjs vi locale', () => {
        expect(dayjs('2026-11-01').locale('vi').format('dddd MMMM')).toBe('chủ nhật tháng 11')
    })

    it('renders Vietnamese weekday and month names in date pickers when the admin is Vietnamese', async () => {
        appRuntime.locale.value = 'vi'

        const text = await openCalendarText()

        expect(text).toContain('T2')
        expect(text).toContain('Th11')
        expect(text).not.toContain('Nov')
    })

    it('renders English names when the admin is English', async () => {
        appRuntime.locale.value = 'en'

        const text = await openCalendarText()

        expect(text).toContain('Mo')
        expect(text).toContain('Nov')
    })
})

describe('dayjs global locale follows the admin language', () => {
    afterEach(() => {
        appRuntime.locale.value = 'en'
    })

    it('renders relative time in the admin language and follows switches', () => {
        dayjs.extend(relativeTime)
        const twoHoursAgo = () => dayjs().subtract(2, 'hour').fromNow()

        appRuntime.locale.value = 'vi'
        expect(twoHoursAgo()).toBe('2 giờ trước')

        appRuntime.locale.value = 'en'
        expect(twoHoursAgo()).toBe('2 hours ago')

        appRuntime.locale.value = 'vi'
        expect(twoHoursAgo()).toBe('2 giờ trước')
    })
})
