import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

vi.mock('@/ajax', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
    },
}))

vi.mock('@/libs', () => ({
    getFunctionsList: vi.fn(),
    getSchedulers: vi.fn(),
    getSettings: vi.fn(),
    getWidgets: vi.fn(),
    getWorkflows: vi.fn(),
    getWorkflow: vi.fn(),
}))

import { agenticCatalogQueryKey, agenticEntityQueryKey } from '@/hooks/useAgentic'
import { settingQueryKey } from '@/hooks/useSetting'

describe('hooks:query keys', () => {
    it('builds serializable agentic catalog keys from reactive filters', () => {
        const filters = {
            status: ref('active'),
            page: ref(1),
            perPage: ref(10),
        }

        expect(agenticCatalogQueryKey('agentic-schedules', filters)).toEqual([
            'agentic-schedules',
            'status=active&page=1&per_page=10',
        ])

        filters.status.value = 'running'
        filters.page.value = 2

        expect(agenticCatalogQueryKey('agentic-schedules', filters)).toEqual([
            'agentic-schedules',
            'status=running&page=2&per_page=10',
        ])
    })

    it('builds serializable entity keys from reactive ids', () => {
        const runId = ref(11)

        expect(agenticEntityQueryKey('agentic-run', runId)).toEqual(['agentic-run', 11])

        runId.value = 15

        expect(agenticEntityQueryKey('agentic-run', runId)).toEqual(['agentic-run', 15])
    })

    it('normalizes setting payload objects before using them as query keys', () => {
        const payload = ref({
            tags: ['beta', 'stable'],
            filters: {
                status: ref('active'),
            },
        })

        expect(settingQueryKey('workflows', payload)).toEqual([
            'workflows',
            {
                filters: {
                    status: 'active',
                },
                tags: ['beta', 'stable'],
            },
        ])
    })
})
