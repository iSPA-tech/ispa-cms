/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// src/components/widget-input-fields.vue, the SHARED typed field renderer used by the page-block editor and by the
// shared-area content form (widget-placement-inspector.vue). The content form itself is covered by
// src/__tests__/widget/placement-inspector-inputs.spec.ts and area-editor.spec.ts.
//
// ant-design-vue tags (a-select/a-checkbox-group/a-radio-group/a-input) are STUBBED rather than mounted for real:
// vitest.config.ts does not load unplugin-vue-components, so antd's auto-import tags never resolve to real
// components in this test environment.

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

// ---------------------------------------------------------------------------
// Shared, hoisted state used by vi.mock factories below.
// ---------------------------------------------------------------------------
const permissionState = vi.hoisted(() => ({
    denied: new Set<string>(),
}))

const apiMocks = vi.hoisted(() => ({
    listWidgetDefinitionCatalog: vi.fn(),
    getWidgetInstanceUsage: vi.fn(),
    createWidgetInstance: vi.fn(),
    updateWidgetInstance: vi.fn(),
    cloneWidgetInstance: vi.fn(),
    archiveWidgetInstance: vi.fn(),
    deleteWidgetInstance: vi.fn(),
    repinWidgetLayoutDraftInstance: vi.fn(),
    bootstrapWidgetLayoutByKey: vi.fn(),
    getWidgetLayoutDraft: vi.fn(),
    listWidgetArtifactManifests: vi.fn(),
}))

vi.mock('../../../../cms/Widget/admin/api', () => apiMocks)

vi.mock('@/mixins', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()
    return {
        ...actual,
        can: (a: unknown, b?: string, c?: string) => {
            const key = c !== undefined ? `${a}.${b}.${c}` : b !== undefined ? `${a}.${b}` : String(a)
            return !permissionState.denied.has(key)
        },
        iAlert: vi.fn(),
    }
})

// ---------------------------------------------------------------------------
// Components under test (imported after mocks)
// ---------------------------------------------------------------------------
import WidgetInputFields from '@/components/widget-input-fields.vue'

// ---------------------------------------------------------------------------
// ant-design-vue stand-ins: real interactive controls with antd's OWN
// `value`/`update:value` v-model convention (NOT the native `modelValue`
// convention), since that is the exact contract i-content-widget-v2.vue's
// original markup already relies on.
// ---------------------------------------------------------------------------
const AInputStub = defineComponent({
    name: 'AInputStub',
    props: ['value', 'placeholder', 'disabled', 'type', 'size'],
    emits: ['update:value', 'change'],
    template: `<input
        :value="value"
        :placeholder="placeholder"
        :disabled="disabled"
        :type="type === 'number' ? 'number' : 'text'"
        @input="$emit('update:value', $event.target.value); $emit('change', $event)"
    />`,
})

const ASelectStub = defineComponent({
    name: 'ASelectStub',
    props: ['value', 'options'],
    emits: ['update:value', 'change'],
    template: `<select :value="value" @change="$emit('update:value', $event.target.value); $emit('change', $event.target.value)">
        <option v-for="opt in (options || [])" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>`,
})

const ACheckboxGroupStub = defineComponent({
    name: 'ACheckboxGroupStub',
    props: ['value', 'options'],
    emits: ['update:value', 'change'],
    template: `<div>
        <label v-for="opt in (options || [])" :key="opt.value">
            <input
                type="checkbox"
                :value="opt.value"
                :checked="(value || []).includes(opt.value)"
                @change="$event.target.checked
                    ? $emit('update:value', [...(value || []), opt.value]) || $emit('change', [...(value || []), opt.value])
                    : $emit('update:value', (value || []).filter((v) => v !== opt.value)) || $emit('change', (value || []).filter((v) => v !== opt.value))"
            />{{ opt.label }}
        </label>
    </div>`,
})

const ARadioGroupStub = defineComponent({
    name: 'ARadioGroupStub',
    props: ['value', 'options'],
    emits: ['update:value', 'change'],
    template: `<div>
        <label v-for="opt in (options || [])" :key="opt.value">
            <input
                type="radio"
                :value="opt.value"
                :checked="value === opt.value"
                @change="$emit('update:value', opt.value); $emit('change', opt.value)"
            />{{ opt.label }}
        </label>
    </div>`,
})

const AFormItemStub = defineComponent({
    name: 'AFormItemStub',
    props: ['label', 'name', 'size', 'noStyle'],
    template: `<div class="a-form-item-stub"><span v-if="label">{{ label }}</span><slot /></div>`,
})

const MediaSelectionStub = defineComponent({
    name: 'MediaSelectionStub',
    props: ['modelValue', 'limit', 'type'],
    emits: ['update:modelValue'],
    template: `<div class="media-selection-stub" :data-limit="limit" :data-type="type">{{ (modelValue || []).length }}</div>`,
})

const AButtonStub = defineComponent({
    name: 'AButtonStub',
    props: ['size', 'danger'],
    emits: ['click'],
    template: `<button type="button" @click="$emit('click', $event)"><slot /></button>`,
})

const ITextareaStub = defineComponent({
    name: 'IContentTextareaEditorStub',
    props: ['modelValue', 'label'],
    emits: ['update:modelValue', 'change'],
    template: `<div class="textarea-stub">{{ label }}<button
        class="textarea-save"
        @click="$emit('update:modelValue', 'edited-' + label); $emit('change', 'edited-' + label)"
    >save</button></div>`,
})

const globalStubs = {
    'a-input': AInputStub,
    'a-select': ASelectStub,
    'a-checkbox-group': ACheckboxGroupStub,
    'a-radio-group': ARadioGroupStub,
    'a-form-item': AFormItemStub,
    'a-button': AButtonStub,
    'media-selection': MediaSelectionStub,
    'i-content-textarea-editor': ITextareaStub,
    'i-icon': true,
}

// =============================================================================
// PART A -- widget-input-fields.vue (the shared typed field renderer)
// =============================================================================
describe('widget-input-fields.vue (shared typed field renderer)', () => {
    const mountFields = (props = {}) =>
        mount(WidgetInputFields, {
            props: { inputs: [], modelValue: {}, ...props },
            global: { stubs: globalStubs },
        })

    it('shows an empty-state message when there are no inputs', () => {
        const wrapper = mountFields({ inputs: [], modelValue: {} })
        expect(wrapper.text()).toContain('text.no-data')
    })

    it('renders a text field and emits the full merged values object on change', async () => {
        const wrapper = mountFields({
            inputs: [{ name: 'title', type: 'text' }],
            modelValue: { title: 'Hello' },
        })

        await wrapper.get('input').setValue('Hello world')

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted).toBeTruthy()
        expect(emitted![emitted!.length - 1][0]).toEqual({ title: 'Hello world' })
    })

    it('renders a select field from item.options and emits the chosen value', async () => {
        const wrapper = mountFields({
            inputs: [{ name: 'layout', type: 'select', options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] }],
            modelValue: { layout: 'a' },
        })

        await wrapper.get('select').setValue('b')

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted![emitted!.length - 1][0]).toEqual({ layout: 'b' })
    })

    it('renders a checkbox-group field and emits an updated array', async () => {
        const wrapper = mountFields({
            inputs: [{ name: 'tags', type: 'checkbox', options: [{ value: 'x', label: 'X' }, { value: 'y', label: 'Y' }] }],
            modelValue: { tags: [] },
        })

        await wrapper.get('input[type="checkbox"]').setValue(true)

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted![emitted!.length - 1][0]).toEqual({ tags: ['x'] })
    })

    it('renders a radio-group field and emits the selected value', async () => {
        const wrapper = mountFields({
            inputs: [{ name: 'align', type: 'radio', options: [{ value: 'left', label: 'Left' }, { value: 'right', label: 'Right' }] }],
            modelValue: { align: 'left' },
        })

        await wrapper.get('input[type="radio"][value="right"]').setValue()

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted![emitted!.length - 1][0]).toEqual({ align: 'right' })
    })

    it('renders a query field as a number input', () => {
        const wrapper = mountFields({
            inputs: [{ name: 'query:latest', type: 'query' }],
            modelValue: { 'query:latest': '5' },
        })

        expect(wrapper.get('input[type="number"]').element.value).toBe('5')
    })

    it('renders an iSeo field as disabled, non-editable', () => {
        const wrapper = mountFields({
            inputs: [{ name: 'seo_title', type: 'iSeo' }],
            modelValue: { seo_title: 'x' },
        })

        expect(wrapper.get('input').attributes('disabled')).toBeDefined()
    })

    it('renders a textarea field via i-content-textarea-editor and emits its saved content', async () => {
        const wrapper = mountFields({
            inputs: [{ name: 'body', type: 'textarea' }],
            modelValue: { body: 'Original' },
        })

        await wrapper.get('.textarea-save').trigger('click')

        const emitted = wrapper.emitted('update:modelValue')
        expect(emitted![emitted!.length - 1][0].body).toContain('edited-')
    })

    it('renders image/upload fields via media-selection honoring a media limit (defaults to 1)', () => {
        const wrapper = mountFields({
            inputs: [{ name: 'gallery', type: 'image', options: { src: 'unknown' } }],
            modelValue: { gallery: [] },
        })

        expect(wrapper.get('.media-selection-stub').attributes('data-limit')).toBe('1')
    })

    it('honors an explicit media limit when the input definition declares one', () => {
        const wrapper = mountFields({
            inputs: [{ name: 'gallery', type: 'upload', media_limit: 5 }],
            modelValue: { gallery: [] },
        })

        expect(wrapper.get('.media-selection-stub').attributes('data-limit')).toBe('5')
    })

    it('renders the media picker for an image field with a known src too', () => {
        const wrapper = mountFields({
            inputs: [{ name: 'icon', type: 'image', options: { src: 'known' } }],
            modelValue: { icon: '' },
        })

        expect(wrapper.find('.media-selection-stub').exists()).toBe(true)
    })

    it('list fields: adds an entry, keeps distinct keys, and removes only the targeted entry', async () => {
        const wrapper = mountFields({
            inputs: [{ name: 'features', type: 'list' }],
            modelValue: { features: ['one', 'two'] },
        })

        expect(wrapper.findAll('[data-index]')).toHaveLength(2)

        await wrapper.get('[data-test="list-add-features"]').trigger('click')
        let emitted = wrapper.emitted('update:modelValue')!
        expect(emitted[emitted.length - 1][0]).toEqual({ features: ['one', 'two', ''] })

        await wrapper.setProps({ modelValue: { features: ['one', 'two', ''] } })
        await wrapper.get('[data-test="list-remove-features-1"]').trigger('click')
        emitted = wrapper.emitted('update:modelValue')!
        expect(emitted[emitted.length - 1][0]).toEqual({ features: ['one', ''] })
    })

    it('never mutates the modelValue prop object directly (always emits a new object)', async () => {
        const modelValue = { title: 'A' }
        const wrapper = mountFields({ inputs: [{ name: 'title', type: 'text' }], modelValue })

        await wrapper.get('input').setValue('B')

        expect(modelValue).toEqual({ title: 'A' })
    })

    it('a field name absent from modelValue is skipped entirely (matches the original item.name in modelValue guard)', () => {
        const wrapper = mountFields({
            inputs: [{ name: 'title', type: 'text' }, { name: 'not_seeded', type: 'text' }],
            modelValue: { title: 'A' },
        })

        expect(wrapper.findAll('input')).toHaveLength(1)
    })
})

// =============================================================================
// PART B -- widget-placement-inspector.vue
// =============================================================================

const instanceFixture = (overrides = {}) => ({
    uuid: 'instance-1',
    project_name: 'default',
    name: 'Hero Banner',
    status: 'active',
    sync_status: 'ready',
    current_version: {
        version: 3,
        widget_id: 10,
        widget_theme_id: 100,
        renderer_key: 'blade',
        renderer_version: 1,
        trust_classification: 'trusted',
        schema: { title: { type: 'text' } },
        values: { title: 'Hello' },
        provider_contract: null,
        created_at: '2026-01-01T00:00:00Z',
    },
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
})

const catalogRow = (overrides = {}) => ({
    widget_id: 10,
    widget_theme_id: 100,
    name: 'Hero',
    theme_name: 'Default',
    label: 'Hero / Default',
    eligible: true,
    manifest_hash: 'hash-a',
    attestation_version: 1,
    applicability: ['default'],
    ...overrides,
})

const catalogRowB = () =>
    catalogRow({ widget_id: 11, widget_theme_id: 101, name: 'Hero', theme_name: 'Alt', label: 'Hero / Alt', manifest_hash: 'hash-b' })

const catalogRowC = () =>
    catalogRow({ widget_id: 12, widget_theme_id: 102, name: 'Hero', theme_name: 'Third', label: 'Hero / Third', manifest_hash: 'hash-c' })
