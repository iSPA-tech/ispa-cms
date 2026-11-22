import { describe, expect, it } from 'vitest'

import { normalizeCategoryTreeRows } from '../../pages/category/category-tree'

describe('category tree rows', () => {
    it('removes empty children from leaf categories so table expand icons stay hidden', () => {
        const rows = [
            {
                id: 1,
                name: 'Parent',
                children: [
                    {
                        id: 2,
                        name: 'Leaf',
                        children: [],
                    },
                ],
            },
            {
                id: 3,
                name: 'Standalone',
                children: [],
            },
        ]

        expect(normalizeCategoryTreeRows(rows)).toEqual([
            {
                id: 1,
                name: 'Parent',
                children: [
                    {
                        id: 2,
                        name: 'Leaf',
                    },
                ],
            },
            {
                id: 3,
                name: 'Standalone',
            },
        ])
        expect(rows[0].children[0]).toHaveProperty('children')
    })
})
