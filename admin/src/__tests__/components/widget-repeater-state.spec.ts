import { describe, expect, it } from 'vitest'
import {
    addItem,
    duplicateItem,
    emptyItem,
    initialItems,
    normalizeItemIds,
    itemTitle,
    moveItem,
    newItemId,
    removeItem,
    repeaterDefinition,
    restoreItem,
} from '@/components/widget-repeater-state.js'
import { getInputVariableByTemplate } from '@/mixins/utils.js'

const def = repeaterDefinition({
    options: {
        item_label: 'Slide',
        title_field: 'title',
        max: 3,
        default_count: 2,
        fields: [
            { name: 'title', type: 'text', label: 'Title' },
            { name: 'photo', type: 'image', label: 'Photo' },
        ],
    },
})
const mk = (id: string, title = '') => ({ _id: id, title, photo: [] })

describe('repeater state', () => {
    it('newItemId is 8 alphanumerics', () => {
        expect(newItemId()).toMatch(/^[A-Za-z0-9]{8}$/)
        expect(newItemId()).not.toBe(newItemId())
    })
    it('repeaterDefinition applies defaults', () => {
        const d = repeaterDefinition({})
        expect(d).toMatchObject({ itemLabel: 'mục', max: 12, defaultCount: 0, fields: [] })
        expect(def).toMatchObject({ itemLabel: 'Slide', max: 3, titleField: 'title', defaultCount: 2 })
    })
    it('emptyItem types fields', () => {
        const i = emptyItem(def)
        expect(i.title).toBe('')
        expect(i.photo).toEqual([])
        expect(i._id).toMatch(/^[A-Za-z0-9]{8}$/)
    })
    it('addItem appends and respects max', () => {
        let items: any[] = [mk('a'), mk('b')]
        const next = addItem(items, def)
        expect(next).toHaveLength(3)
        expect(items).toHaveLength(2)
        expect(addItem(next, def)).toHaveLength(3)
    })
    it('duplicateItem places copy after source with new id', () => {
        const out = duplicateItem([mk('a', 'x'), mk('b')], 'a', def)
        expect(out).toHaveLength(3)
        expect(out[1].title).toBe('x')
        expect(out[1]._id).not.toBe('a')
        expect(out[2]._id).toBe('b')
        expect(duplicateItem([mk('a'), mk('b'), mk('c')], 'a', def)).toHaveLength(3)
    })
    it('remove and restore round-trip', () => {
        const items = [mk('a'), mk('b'), mk('c')]
        const r = removeItem(items, 'b')
        expect(r.items.map((i: any) => i._id)).toEqual(['a', 'c'])
        expect(r.index).toBe(1)
        expect(r.removed._id).toBe('b')
        expect(restoreItem(r.items, r.removed, r.index).map((i: any) => i._id)).toEqual(['a', 'b', 'c'])
    })
    it('moveItem reorders', () => {
        const out = moveItem([mk('a'), mk('b'), mk('c')], 'a', 2)
        expect(out.map((i: any) => i._id)).toEqual(['b', 'c', 'a'])
    })
    it('move then edit by id keeps the value with the item', () => {
        const moved = moveItem([mk('a'), mk('b')], 'a', 1)
        const edited = moved.map((i: any) => (i._id === 'a' ? { ...i, title: 'edited' } : i))
        expect(edited[1]).toMatchObject({ _id: 'a', title: 'edited' })
        expect(edited[0]._id).toBe('b')
    })
    it('itemTitle', () => {
        expect(itemTitle(mk('a', 'Hello'), def, 0, (k: string) => k)).toBe('Slide 1 — Hello')
        expect(itemTitle(mk('a', ''), def, 1, (k: string) => k)).toBe('Slide 2')
    })
    it('initialItems yields defaultCount empties', () => {
        expect(initialItems(def)).toHaveLength(2)
    })
})

describe('getInputVariableByTemplate repeat', () => {
    const content = 'x {repeat:slides template=`<b>{title}</b>`} y'
    it('finds a repeat token', () => {
        const out = getInputVariableByTemplate([], [{ content }])
        const r = out.find((i: any) => i.name === 'slides')
        expect(r?.type).toBe('repeater')
        expect(out.find((i: any) => i.name === 'title')).toBeUndefined()
    })
    it('merges with existing definition keeping options', () => {
        const existing = [{ name: 'slides', type: 'repeater', options: { max: 5 } }]
        const out = getInputVariableByTemplate(existing, [{ content }])
        expect(out.find((i: any) => i.name === 'slides')?.options?.max).toBe(5)
    })
})

describe('fix round 1', () => {
    it('ignores item tokens inside repeat templates', () => {
        const content =
            '{title}{repeat:feedback template=`<img>{image name="feedback.image" class="x"}{upload name="feedback.file"}{iSeo:foo}{feedback.name}`}'
        const out = getInputVariableByTemplate([], [{ content }])
        expect(out.map((i: any) => i.name).sort()).toEqual(['feedback', 'title'])
        expect(out.find((i: any) => i.name === 'feedback')?.type).toBe('repeater')
    })
    it('null max falls back to default', () => {
        for (const max of [null, undefined, '', 0, -2]) {
            expect(repeaterDefinition({ options: { max, min: null } })).toMatchObject({ max: 12, min: 0 })
        }
    })
})

describe('fix wave after final review', () => {
    it('clamps max at 100 like the server', () => {
        expect(repeaterDefinition({ options: { max: 500 } }).max).toBe(100)
        expect(repeaterDefinition({ options: { max: 7 } }).max).toBe(7)
    })
    it('normalizes missing, invalid and duplicate ids and keeps valid unique ones', () => {
        const items = [{ _id: 'AAA', q: 1 }, { q: 2 }, { _id: 'AAA', q: 3 }, { _id: 'bad id', q: 4 }, { _id: 'ok\n', q: 5 }]
        const out = normalizeItemIds(items) as any[]
        expect(out[0]).toBe(items[0])
        const ids = out.map((i) => i._id)
        expect(new Set(ids).size).toBe(5)
        ids.forEach((id) => expect(id).toMatch(/^[A-Za-z0-9]{1,16}$/))
        expect(out.map((i) => i.q)).toEqual([1, 2, 3, 4, 5])
        const clean = [{ _id: 'A1' }]
        expect(normalizeItemIds(clean)).toBe(clean)
    })
})
