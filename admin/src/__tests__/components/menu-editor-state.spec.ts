import { describe, expect, it } from 'vitest'
import { flattenMenu, locateMenu, moveMenuItem } from '@/components/menu-tree-state'
import {
    duplicateMenuBranch,
    newMenuItem,
    prepareMenuItems,
    removedMenuIds,
    serializeMenuItems,
    menuItemIssues,
    validateMenuItems,
} from '@/pages/setting/menu-editor-state'

const legacy = [
    {
        id: 1,
        menu_id: 7,
        name: 'A',
        title: 'Different title',
        type: 'category',
        url: 'old-slug',
        target_type: 'App\\Models\\Category',
        target_id: 10,
        parent_id: null,
        priority: 0,
        custom: {
            isExpandChild: true,
            prefix: { vi: 'chuyen-muc', en: 'category' },
            extra_project_option: { mode: 'existing' },
        },
        items: [
            {
                id: 2,
                menu_id: 7,
                name: 'Child',
                title: 'Child',
                type: 'gallery',
                url: 'gallery',
                target_type: 'App\\Models\\Gallery',
                target_id: 20,
                parent_id: 1,
                priority: 0,
                custom: { action: '_blank', icon: 'existing-icon' },
                items: [],
            },
        ],
    },
    {
        id: 3,
        menu_id: 7,
        name: 'C',
        title: 'C',
        type: 'url',
        url: '/c',
        target_type: '',
        target_id: 0,
        parent_id: null,
        priority: 1,
        custom: {},
        items: [],
    },
]

describe('production menu editor legacy compatibility', () => {
    it('reports every invalid item with a unique outline position and each invalid field', () => {
        const items = prepareMenuItems(legacy)
        items[0].items[0].name = ''
        items[0].items[0].url = ''
        items[0].items.push({ ...newMenuItem(), name: 'Child', url: '' })
        items[1].type = 'category'
        items[1].custom = { isExpandChild: true, expandChildLevel: -1 }
        const issues = menuItemIssues(items)
        expect(issues.map(({ position, names, fields }) => ({ position, names, fields }))).toEqual([
            { position: [1, 1], names: ['A', ''], fields: ['name', 'link'] },
            { position: [1, 2], names: ['A', 'Child'], fields: ['link'] },
            { position: [2], names: ['C'], fields: ['level'] },
        ])
        expect(new Set(issues.map(({ key }) => key)).size).toBe(3)
        expect(validateMenuItems(items)).toEqual({ key: 'item-2', reason: 'name' })
        items[0].items[0].name = 'Fixed'
        items[0].items[0].url = '/fixed'
        expect(menuItemIssues(items).map(({ position }) => position)).toEqual([[1, 2], [2]])
    })
    it('round-trips the old item payload without changing custom, target, prefix, or legacy type', () => {
        const items = prepareMenuItems(legacy)
        expect(serializeMenuItems(items)).toEqual(legacy)
        expect(items[0]).not.toBe(legacy[0])
        items[0].custom.extra_project_option.mode = 'edited'
        expect(legacy[0].custom.extra_project_option.mode).toBe('existing')
    })

    it('moves items across levels without changing their stored identifiers or configuration', () => {
        const items = prepareMenuItems(legacy)
        expect(moveMenuItem(items, 'item-1', 'item-2')).toBe(false)
        expect(moveMenuItem(items, 'item-2', 'item-3')).toBe(true)
        expect(locateMenu(items, 'item-3')!.node.items[0].id).toBe(2)
        expect(serializeMenuItems(locateMenu(items, 'item-3')!.node.items)[0]).toEqual(
            legacy[0].items[0]
        )
    })

    it.each([null, []])(
        'preserves an empty legacy custom field until the operator edits it (%j)',
        (custom) => {
            const source = [{ ...legacy[1], custom }]
            const items = prepareMenuItems(source)
            expect(serializeMenuItems(items)).toEqual(source)
            items[0].custom.action = '_blank'
            expect(serializeMenuItems(items)[0].custom).toEqual({ action: '_blank' })
        }
    )

    it('assigns independent UI keys to unsaved items and resets every DB id in a duplicated branch', () => {
        const items = prepareMenuItems(legacy)
        const copy = duplicateMenuBranch(items[0])
        const fresh = [newMenuItem(), newMenuItem(), copy]
        expect(new Set(flattenMenu(fresh).map(({ node }) => node._key)).size).toBe(4)
        expect(
            flattenMenu([copy]).every(
                ({ node }) => node.id === 0 && node.menu_id === 0 && node.parent_id === 0
            )
        ).toBe(true)
        expect(copy.custom).toEqual(items[0].custom)
        expect(copy._key).not.toBe(items[0]._key)
        expect(removedMenuIds(items[0])).toEqual([2, 1])
    })
})
