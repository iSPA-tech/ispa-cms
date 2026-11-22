import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'

vi.mock('@/config/admin-modules', () => ({ adminResourceWidgets: {} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/ajax', () => ({ default: { get: vi.fn() } }))
const mockLocale = vi.hoisted(() => ({ value: 'vi' }))
const mockEntries = vi.hoisted(() => ({ value: [] as object[] }))
vi.mock('@/runtime', () => ({ useAppRuntime: () => ({ locale: mockLocale }) }))
vi.mock('@/hooks', () => ({ useEnumerates: () => ({ data: mockEntries }) }))

import ajax from '@/ajax'
import { createWidgetResolver, displayWidgets, inputWidgets } from '@/components/resource/widgets'
import DisplayCount from '@/components/resource/widgets/display-count.vue'
import DisplayDatetime from '@/components/resource/widgets/display-datetime.vue'
import DisplayEnum from '@/components/resource/widgets/display-enum.vue'
import DisplayStatus from '@/components/resource/widgets/display-status.vue'
import DisplayText from '@/components/resource/widgets/display-text.vue'
import InputBoolean from '@/components/resource/widgets/input-boolean.vue'
import DisplayEnumerate from '@/components/resource/widgets/display-enumerate.vue'
import InputEnumerate from '@/components/resource/widgets/input-enumerate.vue'
import InputEnum from '@/components/resource/widgets/input-enum.vue'
import DisplayDateRange from '@/components/resource/widgets/display-date-range.vue'
import InputDate from '@/components/resource/widgets/input-date.vue'
import InputDatetime from '@/components/resource/widgets/input-datetime.vue'
import InputNumber from '@/components/resource/widgets/input-number.vue'
import InputTextarea from '@/components/resource/widgets/input-textarea.vue'
import InputText from '@/components/resource/widgets/input-text.vue'

const tagStub = { props: ['color'], template: '<span class="tag" :data-color="color"><slot /></span>' }
const linkStub = { props: ['to'], template: '<a class="link" :data-to="JSON.stringify(to)"><slot /></a>' }
const inputStub = { props: ['value', 'type'], emits: ['update:value'], template: '<input :type="type" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' }
const textareaStub = { props: ['value'], emits: ['update:value'], template: '<textarea :value="value" @input="$emit(\'update:value\', $event.target.value)" />' }
const numberStub = { props: ['value'], emits: ['update:value'], template: '<input class="num" :value="value" @input="$emit(\'update:value\', Number($event.target.value))" />' }
const selectStub = { props: ['value', 'options'], emits: ['update:value'], template: '<select :value="value" @change="$emit(\'update:value\', $event.target.value)"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>' }
const switchStub = { props: ['checked'], emits: ['update:checked'], template: '<button class="sw" :data-on="checked" @click="$emit(\'update:checked\', !checked)" />' }
const datePickerStub = { props: ['value', 'valueFormat', 'showTime', 'format'], emits: ['update:value'], template: '<input class="dp" :data-format="valueFormat" :data-show-time="showTime" :data-display="format" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' }
const numberPropsStub = { props: ['value', 'min', 'max', 'step'], emits: ['update:value'], template: '<input class="num" :data-min="min" :data-max="max" :data-step="step" :value="value" />' }
const global = {
    stubs: {
        'a-date-picker': datePickerStub,
        'a-tag': tagStub,
        'router-link': linkStub,
        'a-input': inputStub,
        'a-textarea': textareaStub,
        'a-input-number': numberStub,
        'a-select': selectStub,
        'a-switch': switchStub,
    },
}

afterEach(() => vi.restoreAllMocks())

describe('display widgets', () => {
    it('text renders the value or a dash', () => {
        expect(mount(DisplayText, { props: { value: 'Acme' } }).text()).toBe('Acme')
        expect(mount(DisplayText, { props: { value: '' } }).text()).toBe('—')
    })

    it('status renders the label with the schema colour', () => {
        const field = { colors: { active: 'green' }, options: [{ value: 'active', label: 'Active' }] }
        const tag = mount(DisplayStatus, { props: { value: 'active', field }, global }).find('.tag')
        expect(tag.text()).toBe('Active')
        expect(tag.attributes('data-color')).toBe('green')
        const byOption = mount(DisplayStatus, { props: { value: 'x', field: { options: [{ value: 'x', label: 'X', color: 'red' }] } }, global })
        expect(byOption.find('.tag').attributes('data-color')).toBe('red')
    })

    it('status maps booleans onto options and colours keyed 1/0', () => {
        const field = { colors: { 1: 'gold' }, options: [{ value: 1, label: 'Current' }, { value: 0, label: 'Not current' }] }
        const on = mount(DisplayStatus, { props: { value: true, field }, global }).find('.tag')
        expect(on.text()).toBe('Current')
        expect(on.attributes('data-color')).toBe('gold')
        expect(mount(DisplayStatus, { props: { value: false, field }, global }).find('.tag').text()).toBe('Not current')
        const boolOptions = { colors: { 1: 'gold' }, options: [{ value: true, label: 'Current' }, { value: false, label: 'Not current' }] }
        const bool = mount(DisplayStatus, { props: { value: true, field: boolOptions }, global }).find('.tag')
        expect(bool.text()).toBe('Current')
        expect(bool.attributes('data-color')).toBe('gold')
    })

    it('enum renders the option label and falls back to the value', () => {
        const field = { options: [{ value: 'a', label: 'Alpha' }] }
        expect(mount(DisplayEnum, { props: { value: 'a', field } }).text()).toBe('Alpha')
        expect(mount(DisplayEnum, { props: { value: 'b', field } }).text()).toBe('b')
    })

    it('count renders a number, as a link when field.link is set', () => {
        expect(mount(DisplayCount, { props: { value: 7 }, global }).text()).toBe('7')
        expect(mount(DisplayCount, { props: { value: 7 }, global }).find('.link').exists()).toBe(false)
        const link = mount(DisplayCount, { props: { value: 7, field: { link: { name: 'x' }, context: 'detail' } }, global }).find('.link')
        expect(link.text()).toBe('7')
        expect(link.attributes('data-to')).toBe('{"name":"x"}')
        expect(mount(DisplayCount, { props: { value: 3, field: { link: '/a', context: 'detail' } }, global }).find('.link').attributes('data-to')).toBe('"/a"')
    })

    describe('datetime expiry option', () => {
        const expiry = { warn_days: 30, expired_label: 'x.expired', warning_label: 'x.soon' }
        const at = (days: number) => new Date(Date.now() + days * 86400000).toISOString()
        const show = (value: string | null, extra: object = {}) => mount(DisplayDatetime, { props: { value, field: { type: 'date', expiry, ...extra } } })

        it('marks a past date as expired with danger tokens, a title and a label', () => {
            const w = show(at(-3))
            expect(w.classes()).toContain('text-danger-ink')
            expect(w.attributes('title')).toBe('x.expired')
            expect(w.text()).toContain('x.expired')
        })

        it('marks a date inside the threshold as expiring with warning tokens', () => {
            const w = show(at(10))
            expect(w.classes()).toContain('text-warning-ink')
            expect(w.attributes('title')).toBe('x.soon')
            expect(w.text()).toContain('x.soon')
        })

        it('leaves a later date, an empty value and a field without the option unmarked', () => {
            expect(show(at(90)).attributes('title')).toBeUndefined()
            expect(show(null).text()).toBe('—')
            expect(mount(DisplayDatetime, { props: { value: at(-3), field: {} } }).attributes('title')).toBeUndefined()
        })

        it('shows the date only with date_only', () => {
            expect(mount(DisplayDatetime, { props: { value: '2026-01-31T17:30:00Z', field: { date_only: true } } }).text()).toBe('01/02/2026')
        })
    })

    it('datetime formats in Asia/Ho_Chi_Minh and shows a dash when empty', () => {
        expect(mount(DisplayDatetime, { props: { value: '2026-01-31T17:30:00Z' } }).text()).toBe('01/02/2026 00:30')
        expect(mount(DisplayDatetime, { props: { value: null } }).text()).toBe('—')
        expect(mount(DisplayDatetime, { props: { value: 'nonsense' } }).text()).toBe('—')
    })
})

describe('input widgets', () => {
    it('text input emits for text, email, textarea and number', async () => {
        const text = mount(InputText, { props: { field: { type: 'text' } }, global })
        await text.find('input').setValue('hi')
        expect(text.emitted('update:modelValue')?.[0]).toEqual(['hi'])

        const email = mount(InputText, { props: { field: { type: 'email' } }, global })
        expect(email.find('input').attributes('type')).toBe('email')

        const area = mount(InputText, { props: { field: { type: 'textarea' } }, global })
        await area.find('textarea').setValue('long')
        expect(area.emitted('update:modelValue')?.[0]).toEqual(['long'])

        const num = mount(InputText, { props: { field: { type: 'number' } }, global })
        await num.find('.num').setValue('5')
        expect(num.emitted('update:modelValue')?.[0]).toEqual([5])
    })

    it('enum input lists options and emits the choice', async () => {
        const wrapper = mount(InputEnum, { props: { modelValue: 'a', field: { options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] } }, global })
        expect(wrapper.findAll('option')).toHaveLength(2)
        await wrapper.find('select').setValue('b')
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['b'])
    })

    it('boolean input toggles', async () => {
        const wrapper = mount(InputBoolean, { props: { modelValue: false }, global })
        await wrapper.find('.sw').trigger('click')
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
    })
})

describe('resolveWidget', () => {
    const Custom = defineComponent({ render: () => h('i', { class: 'custom' }, 'custom') })
    const resolve = createWidgetResolver({ 'demo.badge': () => Promise.resolve(Custom) })

    it('maps built-in types per mode', () => {
        expect(resolve('status')).toBe(displayWidgets.status)
        expect(resolve('datetime')).toBe(DisplayDatetime)
        expect(resolve('count')).toBe(DisplayCount)
        expect(resolve('enum', { mode: 'input' })).toBe(inputWidgets.enum)
        expect(resolve('boolean', { mode: 'input' })).toBe(InputBoolean)
    })

    it('resolves module widgets by <module>.<name> and caches them', async () => {
        const widget = resolve('demo.badge')
        expect(resolve('demo.badge')).toBe(widget)
        const wrapper = mount({ components: { W: widget }, template: '<div><W /></div>' })
        await flushPromises()
        expect(wrapper.html()).toContain('class="custom"')
    })

    it('falls back to plain text for unknown widgets and warns in dev', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        expect(resolve('nope.missing')).toBe(DisplayText)
        expect(resolve('mystery')).toBe(DisplayText)
        expect(warn).toHaveBeenCalledTimes(2)
        vi.stubEnv('DEV', false)
        expect(resolve('mystery')).toBe(DisplayText)
        expect(warn).toHaveBeenCalledTimes(2)
        vi.unstubAllEnvs()
    })

describe('count link', () => {
    it('renders a plain number instead of a link when the count is zero', () => {
        const w = mount(DisplayCount, { props: { value: 0, field: { link: { name: 'x' }, context: 'detail' } }, global })
        expect(w.find('.link').exists()).toBe(false)
        expect(w.text()).toBe('0')
    })
})

    it('renders a plain number in the list even when a link is set and the count is above zero', () => {
        const w = mount(DisplayCount, { props: { value: 5, field: { link: { name: 'x' } } }, global })
        expect(w.find('.link').exists()).toBe(false)
        expect(w.text()).toBe('5')
    })
})

describe('date, number and textarea inputs', () => {
    const resolve = createWidgetResolver({})

    it('resolves the dedicated inputs', () => {
        expect(resolve('date', { mode: 'input' })).toBe(InputDate)
        expect(resolve('number', { mode: 'input' })).toBe(InputNumber)
        expect(resolve('textarea', { mode: 'input' })).toBe(InputTextarea)
        expect(resolve('date_range')).toBe(DisplayDateRange)
    })

    it('date input emits YYYY-MM-DD', async () => {
        const w = mount(InputDate, { props: { modelValue: '2026-01-02', field: {} }, global })
        const dp = w.find('.dp')
        expect(dp.attributes('data-format')).toBe('YYYY-MM-DD')
        await dp.setValue('2026-03-04')
        expect(w.emitted('update:modelValue')![0]).toEqual(['2026-03-04'])
    })

    it('resolves the datetime input and emits a date-time string, null on clear', async () => {
        expect(resolve('datetime', { mode: 'input' })).toBe(InputDatetime)
        const w = mount(InputDatetime, { props: { modelValue: '2026-01-02 03:04:05', field: {} }, global })
        const dp = w.find('.dp')
        expect(dp.attributes('data-format')).toBe('YYYY-MM-DD HH:mm:ss')
        expect(dp.attributes('data-show-time')).toBe('true')
        expect(dp.attributes('data-display')).toBe('DD/MM/YYYY HH:mm')
        await dp.setValue('2026-03-04 10:11:12')
        await dp.setValue('')
        expect(w.emitted('update:modelValue')).toEqual([['2026-03-04 10:11:12'], [null]])
        const custom = mount(InputDatetime, { props: { field: { value_format: 'YYYY-MM-DDTHH:mm' } }, global })
        expect(custom.find('.dp').attributes('data-format')).toBe('YYYY-MM-DDTHH:mm')
    })

    it('datetime input shows an ISO record value as Asia/Ho_Chi_Minh wall time', () => {
        // The API returns toISOString() (UTC); the server reads the submitted wall time in Asia/Ho_Chi_Minh.
        const iso = mount(InputDatetime, { props: { modelValue: '2026-12-01T01:00:00.000000Z', field: {} }, global })
        expect((iso.find('.dp').element as HTMLInputElement).value).toBe('2026-12-01 08:00:00')
        const offset = mount(InputDatetime, { props: { modelValue: '2026-12-01T08:00:00+07:00', field: {} }, global })
        expect((offset.find('.dp').element as HTMLInputElement).value).toBe('2026-12-01 08:00:00')
        const wall = mount(InputDatetime, { props: { modelValue: '2026-12-01 08:00:00', field: {} }, global })
        expect((wall.find('.dp').element as HTMLInputElement).value).toBe('2026-12-01 08:00:00')
    })

    it('number input takes min, max and step from rules and tolerates absence', () => {
        const w = mount(InputNumber, { props: { modelValue: 3, field: { rules: { min: 0, max: 10, step: 0.5 } } }, global: { stubs: { 'a-input-number': numberPropsStub } } })
        const n = w.find('.num')
        expect([n.attributes('data-min'), n.attributes('data-max'), n.attributes('data-step')]).toEqual(['0', '10', '0.5'])
        expect(() => mount(InputNumber, { props: { modelValue: null }, global: { stubs: { 'a-input-number': numberPropsStub } } })).not.toThrow()
    })

    it('textarea input emits the typed value', async () => {
        const w = mount(InputTextarea, { props: { modelValue: 'a', field: {} }, global })
        await w.find('textarea').setValue('b')
        expect(w.emitted('update:modelValue')![0]).toEqual(['b'])
    })
})

describe('date range and secondary cells', () => {
    const field = { start: 'starts_on', end: 'ends_on' }

    it('renders both dates', () => {
        const w = mount(DisplayDateRange, { props: { field, record: { starts_on: '2026-01-02', ends_on: '2026-03-04' } } })
        expect(w.text()).toBe('02/01/2026 – 04/03/2026')
    })

    it('renders one side or a dash', () => {
        expect(mount(DisplayDateRange, { props: { field, record: { starts_on: '2026-01-02' } } }).text()).toBe('02/01/2026')
        expect(mount(DisplayDateRange, { props: { field, record: { ends_on: '2026-03-04' } } }).text()).toBe('04/03/2026')
        expect(mount(DisplayDateRange, { props: { field, record: {} } }).text()).toBe('—')
    })

    it('text cell renders the secondary line only when present', () => {
        const f = { secondary: 'code' }
        const w = mount(DisplayText, { props: { value: 'Algebra', field: f, record: { code: 'MA101' } } })
        expect(w.text()).toContain('Algebra')
        expect(w.find('.r-widget-secondary').text()).toBe('MA101')
        expect(mount(DisplayText, { props: { value: 'Algebra', field: f, record: { code: '' } } }).find('.r-widget-secondary').exists()).toBe(false)
    })
})

describe('enumerate widgets', () => {
    const get = ajax.get as unknown as ReturnType<typeof vi.fn>
    const field = { field: 'status', type: 'enumerate', enumerate: { endpoint: 'enumerate', group: 'status', value_key: 'enum_key', label_key: 'name', alias_key: 'name_alias' } }
    const rows = [
        { enum_key: 'open', name: 'Mở', name_alias: 'Open', type: 'status' },
        { enum_key: 'closed', name: 'Đóng', name_alias: 'Closed', type: 'status' },
    ]
    const spin = { template: '<i class="spin" />' }
    const btn = { emits: ['click'], template: '<button class="retry" @click="$emit(\'click\')"><slot /></button>' }
    const stateSelect = {
        props: ['value', 'options', 'loading'],
        emits: ['update:value'],
        template: '<div class="sel" :data-loading="loading"><span v-for="o in options" :key="o.value" class="opt" :data-value="o.value">{{ o.label }}</span><slot name="notFoundContent" /></div>',
    }
    const g = { stubs: { 'a-select': stateSelect, 'a-spin': spin, 'i-button': btn, 'a-tag': tagStub } }

    beforeEach(() => {
        get.mockReset()
        mockLocale.value = 'vi'
        mockEntries.value = []
    })

    it('is registered for display and input', () => {
        expect(displayWidgets.enumerate).toBe(DisplayEnumerate)
        expect(inputWidgets.enumerate).toBe(InputEnumerate)
    })

    it('input loads the group options with translated labels and emits the key', async () => {
        get.mockResolvedValue({ data: { data: rows } })
        const w = mount(InputEnumerate, { props: { field }, global: g })
        await nextTick()
        expect(w.find('.sel').attributes('data-loading')).toBe('true')
        await flushPromises()
        expect(get).toHaveBeenCalledWith('enumerate', expect.objectContaining({ params: { type: 'status' } }))
        expect(w.findAll('.opt').map((o) => o.text())).toEqual(['Mở', 'Đóng'])
        w.findComponent(stateSelect).vm.$emit('update:value', 'open')
        expect(w.emitted('update:modelValue')![0]).toEqual(['open'])
    })

    it('input uses the alias outside Vietnamese', async () => {
        mockLocale.value = 'en'
        get.mockResolvedValue({ data: { data: rows } })
        const w = mount(InputEnumerate, { props: { field }, global: g })
        await flushPromises()
        expect(w.findAll('.opt').map((o) => o.text())).toEqual(['Open', 'Closed'])
    })

    it('input shows the empty state', async () => {
        get.mockResolvedValue({ data: { data: [] } })
        const w = mount(InputEnumerate, { props: { field }, global: g })
        await flushPromises()
        expect(w.text()).toContain('text.resource.empty')
    })

    it('input shows an error with retry that reloads', async () => {
        get.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce({ data: { data: rows } })
        const w = mount(InputEnumerate, { props: { field }, global: g })
        await flushPromises()
        expect(w.text()).toContain('text.resource.load_failed')
        await w.find('.retry').trigger('click')
        await flushPromises()
        expect(get).toHaveBeenCalledTimes(2)
        expect(w.findAll('.opt')).toHaveLength(2)
    })

    it('display resolves a plain key from the group entries', () => {
        mockEntries.value = rows
        expect(mount(DisplayEnumerate, { props: { value: 'closed', field }, global: g }).find('.tag').text()).toBe('Đóng')
        mockLocale.value = 'en'
        expect(mount(DisplayEnumerate, { props: { value: 'closed', field }, global: g }).find('.tag').text()).toBe('Closed')
    })

    it('display reads an object value and a nested record object', () => {
        const byObject = mount(DisplayEnumerate, { props: { value: { enum_key: 'open', name: 'Mở' }, field }, global: g })
        expect(byObject.find('.tag').text()).toBe('Mở')
        const nested = { ...field, field: 'status.code' }
        const byParent = mount(DisplayEnumerate, { props: { value: 'open', field: nested, record: { status: { code: 'open', name: 'Mở' } } }, global: g })
        expect(byParent.find('.tag').text()).toBe('Mở')
        const byPlural = mount(DisplayEnumerate, { props: { value: 'open', field, record: { statuses: { enum_key: 'open', name: 'Mở' } } }, global: g })
        expect(byPlural.find('.tag').text()).toBe('Mở')
    })

    it('display shows a dash for empty or unresolved values, never the raw key', () => {
        expect(mount(DisplayEnumerate, { props: { value: '', field }, global: g }).text()).toBe('—')
        expect(mount(DisplayEnumerate, { props: { value: 'ghost', field }, global: g }).text()).toBe('—')
    })
})
