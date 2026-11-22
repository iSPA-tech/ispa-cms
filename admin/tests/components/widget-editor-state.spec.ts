import { describe, expect, it } from 'vitest'
import {
    cloneWidgetDraft,
    emptyValueForType,
    formatInputLabel,
    hasWidgetInputData,
    isCurrentPreviewRequest,
    removeWidgetListItem,
    resolveInputValues,
    sortWidgetInputs,
    widgetSnapshot,
} from '@/components/widget-editor-state'

describe('widget editor state helpers', () => {
    it('removes only the selected list item', () => {
        const values = {
            features: ['one', 'two', 'three'],
        }

        expect(removeWidgetListItem(values, 'features', 1)).toEqual({
            features: ['one', 'three'],
        })
        expect(values).toEqual({
            features: ['one', 'two', 'three'],
        })
    })

    it('clones nested widget draft values without sharing references', () => {
        const source = {
            value: {
                title: 'Original',
                image: [{ id: 7 }],
            },
        }

        const draft = cloneWidgetDraft(source)
        draft.value.title = 'Edited'
        draft.value.image[0].id = 8

        expect(source).toEqual({
            value: {
                title: 'Original',
                image: [{ id: 7 }],
            },
        })
    })

    it('detects meaningful widget input data for discard protection', () => {
        expect(hasWidgetInputData({ title: '', image: [], enabled: false })).toBe(false)
        expect(hasWidgetInputData({ title: 'Edited' })).toBe(true)
        expect(hasWidgetInputData({ image: [{ id: 7 }] })).toBe(true)
    })

    it('accepts only the latest preview request for the same draft', () => {
        const snapshot = widgetSnapshot({ widget_id: 1, template_id: 2, value: { title: 'A' } })

        expect(isCurrentPreviewRequest(2, 2, snapshot, snapshot)).toBe(true)
        expect(isCurrentPreviewRequest(1, 2, snapshot, snapshot)).toBe(false)
        expect(isCurrentPreviewRequest(2, 2, snapshot, widgetSnapshot({ title: 'B' }))).toBe(false)
    })

    // =========================================================================
    // Chunk 3 Task 28: pure helpers extracted so widget-input-fields.vue (the
    // shared typed field renderer used by BOTH the Page Widget editor and the
    // Site Layout Inspector) and i-content-widget-v2.vue's own definition
    // (widget/template) switch flow apply the IDENTICAL carryover/defaulting/
    // sorting/labeling rules.
    // =========================================================================

    describe('emptyValueForType', () => {
        it('defaults media/choice/list types to an empty array', () => {
            expect(emptyValueForType('image')).toEqual([])
            expect(emptyValueForType('upload')).toEqual([])
            expect(emptyValueForType('checkbox')).toEqual([])
            expect(emptyValueForType('radio')).toEqual([])
            expect(emptyValueForType('select')).toEqual([])
            expect(emptyValueForType('list')).toEqual([])
        })

        it('defaults every other type to an empty string', () => {
            expect(emptyValueForType('text')).toBe('')
            expect(emptyValueForType('textarea')).toBe('')
            expect(emptyValueForType('query')).toBe('')
            expect(emptyValueForType('unknown-type')).toBe('')
        })
    })

    describe('resolveInputValues (definition-switch compatible-value preservation)', () => {
        it('carries over a non-empty value already present for a same-named input', () => {
            const inputs = [{ name: 'title', type: 'text' }]
            const result = resolveInputValues(inputs, { title: 'Existing' }, {})

            expect(result).toEqual({ title: 'Existing' })
        })

        it('falls back to the original baseline value when the current value is absent (nullish)', () => {
            const inputs = [{ name: 'title', type: 'text' }]
            const result = resolveInputValues(inputs, {}, { title: 'Baseline' })

            expect(result).toEqual({ title: 'Baseline' })
        })

        it('an explicit empty-string current value is NOT nullish, so it does not fall back to the original (matches the original ?? operator behavior exactly)', () => {
            const inputs = [{ name: 'title', type: 'text' }]
            const result = resolveInputValues(inputs, { title: '' }, { title: 'Baseline' })

            expect(result).toEqual({ title: '' })
        })

        it('defaults an input with no current or original value by its type', () => {
            const inputs = [
                { name: 'title', type: 'text' },
                { name: 'gallery', type: 'image' },
            ]
            const result = resolveInputValues(inputs, {}, {})

            expect(result).toEqual({ title: '', gallery: [] })
        })

        it('never carries over a value for an input name absent from the new input set', () => {
            const inputs = [{ name: 'title', type: 'text' }]
            const result = resolveInputValues(inputs, { title: 'Kept', obsolete: 'Dropped' }, {})

            expect(result).toEqual({ title: 'Kept' })
            expect(result).not.toHaveProperty('obsolete')
        })
    })

    describe('sortWidgetInputs', () => {
        it('sorts alphabetically by name', () => {
            const inputs = [{ name: 'zeta', type: 'text' }, { name: 'alpha', type: 'text' }]
            expect(sortWidgetInputs(inputs).map((i) => i.name)).toEqual(['alpha', 'zeta'])
        })

        it('pushes image/upload/textarea types after every other type', () => {
            const inputs = [
                { name: 'a_image', type: 'image' },
                { name: 'z_text', type: 'text' },
            ]
            expect(sortWidgetInputs(inputs).map((i) => i.name)).toEqual(['z_text', 'a_image'])
        })

        it('does not mutate the input array (pure function)', () => {
            const inputs = [{ name: 'zeta', type: 'text' }, { name: 'alpha', type: 'text' }]
            const original = [...inputs]

            sortWidgetInputs(inputs)

            expect(inputs).toEqual(original)
        })
    })

    describe('formatInputLabel', () => {
        it('title-cases the input name and appends its {TOKEN} when no label/title is set', () => {
            expect(formatInputLabel({ name: 'hero_title' }, 'Unknown')).toBe('Hero title · {HERO_TITLE}')
        })

        it('prefers an explicit label, then title, over the raw name', () => {
            expect(formatInputLabel({ name: 'x', label: 'Hero Title' }, 'Unknown')).toBe('Hero Title · {X}')
            expect(formatInputLabel({ name: 'x', title: 'Fallback Title' }, 'Unknown')).toBe(
                'Fallback Title · {X}'
            )
        })

        it('falls back to the supplied unknown-label string when nothing identifies the field', () => {
            expect(formatInputLabel({}, 'Unknown')).toBe('Unknown')
        })
    })
})
