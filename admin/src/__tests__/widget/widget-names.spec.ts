// Widget and design names follow the admin language: screens show `display_name` (the API localizes it) and fall back
// to the original `name`; the design editor edits the Vietnamese and English names and saves them as `name_translations`.

import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: () => true }) }))

const widgetRows = vi.hoisted(() => ({ value: [] as unknown[] }))
vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')
    return { useWidgets: () => ({ data: ref({ data: widgetRows.value }) }) }
})
vi.mock('@/libs', () => ({ searchFilterWidgets: vi.fn().mockResolvedValue([]), getWidgetPreview: vi.fn() }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn(), rule: () => true }))

import { designLabel, localizedName, widgetDescription, widgetLabel } from '@/libs/widget-names'
import LibraryCard from '../../../../cms/Core/admin/pages/widget-library/components/library-card.vue'
import NameTranslations from '../../../../cms/Core/admin/components/widget-source/widget-name-translations.vue'
import SelectInputWidget from '@/components/select-input-widget.vue'
import { widgetDisplayName } from '../../../../cms/Widget/admin/area-format'

const { buildWidgetSavePayload } = await vi.importActual<typeof import('@/libs/widget')>('@/libs/widget')

const $t = (key: string) => key

describe('widgetLabel', () => {
    it('prefers the localized name and falls back to the original one', () => {
        expect(widgetLabel({ name: 'Intro', display_name: 'Giới thiệu' })).toBe('Giới thiệu')
        expect(widgetLabel({ name: 'Intro', display_name: '' })).toBe('Intro')
        expect(widgetLabel({ name: 'Intro' })).toBe('Intro')
        expect(widgetLabel(null)).toBe('')
        expect(designLabel({ name: 'Two columns', display_name: 'Hai cột' })).toBe('Hai cột')
        expect(widgetDescription({ description: 'About', display_description: 'Giới thiệu ngắn' })).toBe('Giới thiệu ngắn')
        expect(widgetDescription({ description: 'About' })).toBe('About')
    })

    it('names an unsaved row the way the API will: that language, else English, else the original name', () => {
        expect(localizedName({ vi: 'Hai cột', en: 'Two columns' }, 'two-col', 'vi')).toBe('Hai cột')
        expect(localizedName({ en: 'Two columns' }, 'two-col', 'vi')).toBe('Two columns')
        expect(localizedName({ vi: '  ' }, 'two-col', 'vi')).toBe('two-col')
        expect(localizedName(null, 'two-col', 'en')).toBe('two-col')
    })
})

describe('shared area editor', () => {
    it('names a placed widget in the admin language, else by its original name', () => {
        expect(widgetDisplayName($t, { widget_name: 'Intro', widget_display_name: 'Giới thiệu' })).toBe('Giới thiệu')
        expect(widgetDisplayName($t, { widget_name: 'Intro' })).toBe('Intro')
        expect(widgetDisplayName($t, null)).toBe('widget_site_layout.area.widget_unnamed')
    })
})

describe('library card', () => {
    const mountCard = (widget: Record<string, unknown>) =>
        mount(LibraryCard as never, {
            props: { widget: { id: 10, origin: 'site', template: [{ id: 100, name: 'Default' }], ...widget } },
            global: { stubs: { 'i-button': { template: '<button><slot /></button>' }, 'i-icon': true }, mocks: { $t } },
        })

    it('shows the name in the admin language', () => {
        expect(mountCard({ name: 'Intro', display_name: 'Giới thiệu' }).get('h3').text()).toBe('Giới thiệu')
    })

    it('falls back to the original name', () => {
        expect(mountCard({ name: 'Intro' }).get('h3').text()).toBe('Intro')
    })
})

describe('page editor widget picker', () => {
    it('lists widgets by their name in the admin language, else the original one', async () => {
        widgetRows.value = [
            { id: 1, name: 'Intro', display_name: 'Giới thiệu' },
            { id: 2, name: 'Contact' },
        ]
        const wrapper = mount(SelectInputWidget as never, {
            global: {
                stubs: { 'a-form-item': { template: '<div><slot /></div>' }, 'a-select': { name: 'ASelect', props: ['options'], template: '<div />' } },
                mocks: { $t },
            },
        })
        await flushPromises()

        expect(wrapper.getComponent({ name: 'ASelect' }).props('options')).toEqual([
            { label: 'Giới thiệu', value: 1 },
            { label: 'Contact', value: 2 },
        ])
    })
})

describe('name fields of the design editor', () => {
    const InputStub = {
        props: ['value'],
        emits: ['update:value'],
        template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
    }
    const mountFields = (props: Record<string, unknown>) =>
        mount(NameTranslations as never, {
            props,
            global: {
                stubs: { 'a-form-item': { template: '<div><slot /></div>' }, 'a-input': InputStub, 'a-textarea': InputStub },
                mocks: { $t },
            },
        })

    it('shows the saved names and reports a change as name_translations', async () => {
        const wrapper = mountFields({ modelValue: { vi: 'Giới thiệu' } })

        expect((wrapper.get('[data-test="name-vi"]').element as HTMLInputElement).value).toBe('Giới thiệu')
        expect((wrapper.get('[data-test="name-en"]').element as HTMLInputElement).value).toBe('')

        await wrapper.get('[data-test="name-en"]').setValue('Introduction')

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ vi: 'Giới thiệu', en: 'Introduction' }])
    })

    it('edits the descriptions of a widget', async () => {
        const wrapper = mountFields({ modelValue: null, kind: 'description' })

        await wrapper.get('[data-test="description-vi"]').setValue('Khối giới thiệu')

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([{ vi: 'Khối giới thiệu' }])
    })
})

describe('buildWidgetSavePayload', () => {
    it('sends the names per language of the widget and of each design, without blanks', () => {
        const payload = buildWidgetSavePayload({
            id: 5,
            name: 'Intro',
            name_translations: { vi: ' Giới thiệu ', en: '' },
            description_translations: { vi: '', en: '   ' },
            template: [
                { id: 1, name: 'Two columns', name_translations: { en: 'Two columns', vi: 'Hai cột' } },
                { id: 2, name: 'Plain' },
            ],
            inputs: [],
        })

        expect(payload.name).toBe('Intro')
        expect(payload.name_translations).toEqual({ vi: 'Giới thiệu' })
        expect(payload.description_translations).toBeNull()
        expect(payload.template[0].name_translations).toEqual({ en: 'Two columns', vi: 'Hai cột' })
        expect(payload.template[1]).not.toHaveProperty('name_translations')
    })
})
