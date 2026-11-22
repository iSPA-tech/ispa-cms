import { describe, expect, it } from 'vitest'
import { draftFingerprint, outlineGroups, fieldReferences, moveFormField, duplicateFormField, removeFormField, formItemIssues, serializeFormDraft } from '../../../cms/Content/admin/pages/contact/form-workspace-state'

const field = (field_name: string, custom = {}, extras = {}) => ({ id: 1, name: field_name, field_name, type: 'select', status: true, options: [{ value: 'a', label: 'A' }], custom, ...extras })

describe('Contact form workspace state', () => {
    it('derives ordered groups from existing section markers without changing items', () => {
        const items = [field('first'), field('city', { section: 'Địa chỉ' }), field('ward')]
        const before = JSON.stringify(items)
        const groups = outlineGroups(items, 'ward')
        expect(groups).toHaveLength(1)
        expect(groups[0].label).toBe('Địa chỉ')
        expect(groups[0].rows[0].index).toBe(2)
        expect(JSON.stringify(items)).toBe(before)
    })

    it('ignores editor expansion and object-key order, not real data or item order', () => {
        const form = { name: 'TEST', items: [field('one', { unknown: { a: 1, b: 2 } }), field('two')] }
        const copy = JSON.parse(JSON.stringify(form))
        copy.items[0].isShow = true
        copy.items[0].custom.unknown = { b: 2, a: 1 }
        expect(draftFingerprint(copy)).toBe(draftFingerprint(form))
        copy.items.reverse()
        expect(draftFingerprint(copy)).not.toBe(draftFingerprint(form))
        expect(serializeFormDraft(copy).items[1]).not.toHaveProperty('isShow')
        expect(serializeFormDraft(copy).items[1].custom.unknown).toEqual({ b: 2, a: 1 })
    })

    it('moves items without rewriting identities or silently breaking earlier-field dependencies', () => {
        const items = [field('city'), field('ward', { optionsSource: { source: 'core.localities', filters: {}, dependsOn: { parent: 'city' } } }), field('note')]
        expect(moveFormField(items, 'ward', 'city').blockedBy.map(row => row.field_name)).toEqual(['ward'])
        const moved = moveFormField(items, 'note', 'city')
        expect(moved.blockedBy).toEqual([])
        expect(moved.items.map(item => item.field_name)).toEqual(['note', 'city', 'ward'])
        expect(moved.items[2]).toBe(items[1])
        expect(items.map(item => item.field_name)).toEqual(['city', 'ward', 'note'])
    })

    it('duplicates one field with a fresh key and independent settings, keeping option values', () => {
        const original = field('city', { section: 'Địa chỉ', unknown: { keep: true } }, { id: 8, field_id: 3, description: 'Guide' })
        const result = duplicateFormField([original], 'city', 'new-city')
        expect(result[1]).toMatchObject({ id: 0, field_name: 'new-city', description: 'Guide', options: original.options })
        expect(result[1].custom.section).toBeNull()
        result[1].custom.unknown.keep = false
        expect(original.custom.unknown.keep).toBe(true)
        expect(result[1].options).not.toBe(original.options)
    })

    it('removes only draft items and reports every referencing item', () => {
        const items = [field('city'), field('ward', { optionsSource: { source: 'core.localities', dependsOn: { parent: 'city' } } }), field('note', { visibleWhen: { field: 'city', value: 'a' } })]
        expect(fieldReferences(items, 'city').map(item => item.field_name)).toEqual(['ward', 'note'])
        expect(removeFormField(items, 'city').blockedBy).toHaveLength(2)
        expect(removeFormField(items, 'note').items.map(item => item.field_name)).toEqual(['city', 'ward'])
        expect(items).toHaveLength(3)
    })

    it('identifies exact invalid settings without requiring internal IDs to be typed', () => {
        const items = [field('city'), field('ward', { optionsSource: { source: 'core.localities', dependsOn: { parent: null } } }, { name: '' })]
        const issues = formItemIssues(items)
        expect(issues).toEqual(expect.arrayContaining([
            expect.objectContaining({ index: 1, key: 'ward', attribute: 'name', reason: 'workspace_error_name' }),
            expect.objectContaining({ index: 1, key: 'ward', attribute: 'custom.optionsSource', reason: 'workspace_error_dependency' }),
        ]))
    })
})
