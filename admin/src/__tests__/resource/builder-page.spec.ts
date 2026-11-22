import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
const nav = vi.hoisted(() => ({ route: { query: {} } as { query: Record<string, any> }, replaced: [] as any[] }))
vi.mock('vue-router', () => ({
    useRoute: () => nav.route,
    useRouter: () => ({
        replace: (to: { query: Record<string, any> }) => {
            nav.replaced.push(to)
            nav.route.query = to.query
        },
    }),
}))

const getSchema = vi.hoisted(() => vi.fn())
vi.mock('@/libs/resource', () => ({ getResourceSchema: getSchema }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/resource/resource-list.vue', () => ({ default: { props: ['schema'], template: '<div class="list" />' } }))
vi.mock('@/components/resource/resource-detail.vue', () => ({ default: { props: ['uuid'], template: '<div class="detail" />' } }))
vi.mock('@/components/resource/resource-form.vue', () => ({ default: { props: ['fields'], template: '<form class="form" />' } }))

const stubs = {
    breadcrumbs: { template: '<div><slot /></div>' },
    'i-box': { template: '<div><slot /></div>' },
    'i-button': { template: '<button type="button" :data-test="$attrs[\'data-test\']"><slot /></button>' },
    'a-skeleton': { template: '<div />' },
    'a-alert': { template: '<div />' },
    'a-drawer': { props: ['open'], template: '<div v-if="open" class="drawer"><slot /></div>' },
}
const form = { endpoint: { method: 'POST', path: '/api/v2/admin/x/items' }, fields: [] }
const mountPage = async (schema: Record<string, unknown>) => {
    getSchema.mockResolvedValue({ schema_version: 1, list: { columns: [] }, ...schema })
    const { default: Page } = await import('@/components/resource/resource-page.vue')
    const w = mount<any>(Page, { props: { resource: 'x/items', title: 'nav.x' }, global: { stubs } })
    await flushPromises()
    return w
}

describe('ResourcePage abilities', () => {
    beforeEach(() => vi.resetAllMocks())

    it('hides create for a view-only user but keeps reload and the list', async () => {
        const w = await mountPage({ abilities: { view: true, manage: false }, form: { create: form, update: form } })
        expect(w.find('[data-test="create"]').exists()).toBe(false)
        expect(w.find('[data-test="reload"]').exists()).toBe(true)
        expect(w.find('.list').exists()).toBe(true)
    })

    it('shows create for a manager and for a schema without abilities', async () => {
        expect((await mountPage({ abilities: { view: true, manage: true }, form: { create: form } })).find('[data-test="create"]').exists()).toBe(true)
        expect((await mountPage({ form: { create: form } })).find('[data-test="create"]').exists()).toBe(true)
    })
})
