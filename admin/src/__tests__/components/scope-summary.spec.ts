import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ScopeSummary from '@/components/data-scope/scope-summary.vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params: Record<string, unknown> = {}) =>
            key === 'text.helpdesk.scope.organization'
                ? 'Organization (en)'
                : key.replace(/^text\.data_scope\./, '') + (params.n !== undefined ? `:${params.n}` : ''),
        te: (key: string) => key === 'text.helpdesk.scope.organization',
    }),
}))

const node = (n: number, extra = {}) => ({ key: `k${n}`, label: `Node ${n}`, trail: [`Node ${n}`], expansion: 'self', ...extra })
const mountSummary = (summary: unknown) => mount(ScopeSummary, { props: { summary } })

describe('scope-summary', () => {
    it('renders nothing for a null summary', () => {
        expect(mountSummary(null).text()).toBe('')
    })

    it.each([
        ['all', 'level_all'],
        ['own', 'level_own'],
        ['none', 'level_none'],
    ])('renders the %s level wording', (level, key) => {
        const w = mountSummary({ level, own: level === 'own', dimensions: [] })
        expect(w.text()).toContain(key)
    })

    it('shows trail, expansion wording and never the node key', () => {
        const w = mountSummary({
            level: 'nodes',
            own: false,
            dimensions: [{
                name: 'org', label: 'Organization', more: 0,
                nodes: [
                    node(1, { trail: ['Root', 'Branch', 'Node 1'], expansion: 'descendants' }),
                    node(2, { expansion: 'branch' }),
                    node(3, { expansion: 'self' }),
                ],
            }],
        })
        const text = w.text()
        expect(text).toContain('Organization')
        expect(text).toContain('Root › Branch › Node 1')
        expect(text).toContain('expansion_descendants')
        expect(text).toContain('expansion_branch')
        expect(text).toContain('expansion_self')
        expect(text).not.toContain('k1')
    })

    it('mixed also shows the own line', () => {
        const w = mountSummary({ level: 'mixed', own: true, dimensions: [{ name: 'org', label: 'Org', more: 0, nodes: [node(1)] }] })
        expect(w.text()).toContain('level_own')
        expect(w.text()).toContain('Node 1')
    })

    it('shows at most 5 nodes and a +n count including the dimension more', () => {
        const w = mountSummary({
            level: 'nodes', own: false,
            dimensions: [{ name: 'org', label: 'Org', more: 4, nodes: [1, 2, 3, 4, 5, 6, 7].map((n) => node(n)) }],
        })
        expect(w.text()).toContain('Node 5')
        expect(w.text()).not.toContain('Node 6')
        expect(w.text()).toContain('more:6')
    })

    it('shows no +n when nothing is hidden', () => {
        const w = mountSummary({ level: 'nodes', own: false, dimensions: [{ name: 'o', label: 'O', more: 0, nodes: [node(1)] }] })
        expect(w.text()).not.toContain('more')
    })

    it('translates label_key instead of the frozen label when the key resolves', () => {
        const w = mountSummary({
            level: 'nodes', own: false,
            dimensions: [{ name: 'organization', label: 'Tổ chức', label_key: 'text.helpdesk.scope.organization', more: 0, nodes: [node(1)] }],
        })
        expect(w.text()).toContain('Organization (en)')
        expect(w.text()).not.toContain('Tổ chức')
    })

    it('falls back to label when label_key is missing or unknown', () => {
        const old = mountSummary({ level: 'nodes', own: false, dimensions: [{ name: 'o', label: 'Tổ chức', more: 0, nodes: [node(1)] }] })
        expect(old.text()).toContain('Tổ chức')
        const unknown = mountSummary({ level: 'nodes', own: false, dimensions: [{ name: 'o', label: 'Frozen', label_key: 'no.such.key', more: 0, nodes: [node(1)] }] })
        expect(unknown.text()).toContain('Frozen')
    })
})
