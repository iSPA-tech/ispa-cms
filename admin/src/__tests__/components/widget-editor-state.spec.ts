import { describe, expect, it } from 'vitest'
import { emptyValueForType, formatInputLabel, resolveInputValues } from '@/components/widget-editor-state'

const messages: Record<string, string> = {
    'validation.attributes.title': 'Tiêu đề',
    'text.widget.custom_label': 'Nhãn riêng',
}
const translate = (key: string) => messages[key] ?? key

describe('formatInputLabel', () => {
    it('keeps the title-cased name without a translator', () => {
        expect(formatInputLabel({ name: 'cta_text' }, 'Không rõ', { withToken: false })).toBe('Cta text')
    })

    it('resolves an input named title to the common field label', () => {
        expect(formatInputLabel({ name: 'title' }, 'Không rõ', { withToken: false, translate })).toBe('Tiêu đề')
    })

    it('falls back to the title-cased name when no field label exists', () => {
        expect(formatInputLabel({ name: 'subtitle' }, 'Không rõ', { withToken: false, translate })).toBe('Subtitle')
    })

    it('translates an explicit label that is a translation key', () => {
        expect(
            formatInputLabel({ name: 'x', label: 'text.widget.custom_label' }, '', { withToken: false, translate })
        ).toBe('Nhãn riêng')
    })

    it('uses a label stored in the input options', () => {
        expect(
            formatInputLabel({ name: 't2_role', options: { value: null, label: 'Vai trò khách 2' } }, '', { withToken: false, translate })
        ).toBe('Vai trò khách 2')
    })

    it('labels a numbered field from its base field label', () => {
        expect(formatInputLabel({ name: 'title_2' }, '', { withToken: false, translate })).toBe('Tiêu đề 2')
    })

    it('keeps the title-cased name for a numbered field without a base label', () => {
        expect(formatInputLabel({ name: 'subtitle_3' }, '', { withToken: false, translate })).toBe('Subtitle 3')
    })

    it('prefers a top-level label over options.label', () => {
        expect(formatInputLabel({ name: 'x', label: 'Riêng', options: { label: 'Khác' } }, '', { withToken: false })).toBe('Riêng')
    })

    it('uses the unknown label when the input has no name or label', () => {
        expect(formatInputLabel({}, 'Không rõ', { translate })).toBe('Không rõ')
    })

    it('appends the upper-case token by default', () => {
        expect(formatInputLabel({ name: 'title' }, '', { translate })).toBe('Tiêu đề · {TITLE}')
    })
})

describe('repeater empty value', () => {
    it('is an empty list and a stored empty list stays one', () => {
        expect(emptyValueForType('repeater')).toEqual([])
        expect(resolveInputValues([{ name: 'fb', type: 'repeater' }], { fb: [] }, {})).toEqual({ fb: [] })
    })
})
