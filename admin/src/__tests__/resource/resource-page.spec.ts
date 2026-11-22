import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
const nav = vi.hoisted(() => ({ route: { query: {} } as { query: Record<string, any>; fullPath?: string }, replaced: [] as any[], pushed: [] as any[] }))
vi.mock('vue-router', () => ({
    useRoute: () => nav.route,
    useRouter: () => ({
        push: (to: unknown) => {
            nav.pushed.push(to)
        },
        replace: (to: { query: Record<string, any> }) => {
            nav.replaced.push(to)
            nav.route.query = to.query
        },
    }),
}))

const getSchema = vi.hoisted(() => vi.fn())
vi.mock('@/libs/resource', () => ({ getResourceSchema: getSchema }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }),
}))
vi.mock('@/components/resource/resource-list.vue', () => ({
    default: {
        props: ['schema'],
        emits: ['open'],
        template: '<div class="list"><button class="row" @click="$emit(\'open\', { uuid: \'0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40\' })" /></div>',
    },
}))
vi.mock('@/components/saved-views-bar.vue', () => ({
    default: {
        props: ['listKey', 'schema', 'state', 'canShare'],
        template: '<div class="views" :data-key="listKey" :data-share="String(canShare)" :data-state="JSON.stringify(state)" :data-schema="JSON.stringify(schema)" />',
    },
}))
vi.mock('@/components/resource/resource-detail.vue', () => ({
    default: {
        props: ['uuid'],
        emits: ['changed', 'loaded', 'edit', 'close'],
        template:
            '<div class="detail" :data-uuid="uuid"><button class="edit" @click="$emit(\'edit\', { uuid: \'0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40\', name: \'Acme\' })" /><button class="close" @click="$emit(\'close\')" /><button class="changed" @click="$emit(\'changed\')" /><button class="loaded" @click="$emit(\'loaded\', { name: \'Loaded Co\' })" /></div>',
    },
}))
vi.mock('@/components/resource/resource-form.vue', () => ({
    default: {
        props: ['fields', 'endpoint', 'record', 'successMessage', 'submitLabel', 'requiredLabel'],
        emits: ['saved', 'cancel'],
        template:
            '<form class="form" :data-method="endpoint.method" :data-path="endpoint.path" :data-name="record && record.name" :data-msg="successMessage" :data-submit="submitLabel" :data-required="requiredLabel"><button class="saved" type="button" @click="$emit(\'saved\', {})" /><button class="saved-new" type="button" @click="$emit(\'saved\', { data: { data: { uuid: \'5c1a9e22-3b7d-4e0f-8a16-9d4f2c6b7e18\', name: \'Fresh\' } } })" /></form>',
    },
}))

const stubs = {
    breadcrumbs: { props: ['items'], template: '<div class="bc" :data-items="JSON.stringify(items)"><slot /><slot name="description" /></div>' },
    'i-box': { template: '<div><slot /></div>' },
    'i-button': { emits: ['click'], template: '<button type="button" :data-test="$attrs[\'data-test\']" @click="$emit(\'click\')"><slot /></button>' },
    'a-skeleton': { template: '<div class="skeleton" />' },
    'a-alert': { props: ['message'], template: '<div role="alert">{{ message }}<slot name="action" /></div>' },
    'a-drawer': { props: ['open', 'title'], template: '<div v-if="open" class="drawer" :data-title="title"><slot /></div>' },
}

const schema = (over: Record<string, unknown> = {}) => ({
    schema_version: 1,
    list: { columns: [] },
    form: { create: { endpoint: { method: 'POST', path: '/api/v2/admin/x/items' }, fields: [], success_message: 'text.x.created' } },
    ...over,
})

const mountPage = async (props: Record<string, unknown> = {}) => {
    const { default: Page } = await import('@/components/resource/resource-page.vue')
    const w = mount<any>(Page, { props: { resource: 'helpdesk/organizations', title: 'nav.x', ...props }, global: { stubs } })
    await flushPromises()
    return w
}

describe('ResourcePage', () => {
    beforeEach(() => {
        vi.resetAllMocks()
        nav.route = reactive({ query: {} })
        nav.replaced = []
        nav.pushed = []
    })

    it('loads the schema for module/resource and renders the list', async () => {
        getSchema.mockResolvedValue(schema())
        const w = await mountPage()
        expect(getSchema).toHaveBeenCalledWith('helpdesk', 'organizations')
        expect(w.find('.list').exists()).toBe(true)
    })

    it('renders the saved views bar above the list with the resource key and the list state schema', async () => {
        getSchema.mockResolvedValue(schema({ list: { columns: [], search: {}, filters: [{ field: 'status' }] } }))
        nav.route = reactive({ query: { status: 'suspended', q: 'acme', record: '0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40' } })
        const w = await mountPage()
        const bar = w.find('.views')
        expect(bar.exists()).toBe(true)
        expect(bar.attributes('data-key')).toBe('helpdesk/organizations')
        expect(JSON.parse(bar.attributes('data-schema') as string)).toEqual({ page: 'int', q: 'string', status: 'string' })
        expect(JSON.parse(bar.attributes('data-state') as string)).toMatchObject({ status: 'suspended', q: 'acme' })
        expect(JSON.parse(bar.attributes('data-state') as string).record).toBeUndefined()
        expect(w.html().indexOf('class="views"')).toBeLessThan(w.html().indexOf('class="list"'))
    })

    it('lets the bar share views only when the schema has a form', async () => {
        getSchema.mockResolvedValue(schema())
        expect((await mountPage()).find('.views').attributes('data-share')).toBe('true')
        getSchema.mockResolvedValue(schema({ form: undefined }))
        expect((await mountPage()).find('.views').attributes('data-share')).toBe('false')
    })

    it('hides the saved views bar while the schema loads, fails or is too new', async () => {
        getSchema.mockRejectedValueOnce(new Error('x'))
        expect((await mountPage()).find('.views').exists()).toBe(false)
        getSchema.mockResolvedValue(schema({ schema_version: 2 }))
        expect((await mountPage()).find('.views').exists()).toBe(false)
    })

    it('shows the create button only when the schema has form.create', async () => {
        getSchema.mockResolvedValue(schema())
        expect((await mountPage()).find('[data-test="create"]').exists()).toBe(true)
        getSchema.mockResolvedValue(schema({ form: {} }))
        expect((await mountPage()).find('[data-test="create"]').exists()).toBe(false)
    })

    it('shows only the update notice for a newer schema version', async () => {
        getSchema.mockResolvedValue(schema({ schema_version: 2 }))
        const w = await mountPage()
        expect(w.find('[role="alert"]').text()).toContain('text.resource.update_needed')
        expect(w.find('.list').exists()).toBe(false)
        expect(w.find('.drawer').exists()).toBe(false)
    })

    it('shows a load error with retry that recovers', async () => {
        getSchema.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce(schema())
        const w = await mountPage()
        expect(w.find('[role="alert"]').text()).toContain('text.resource.load_failed')
        await w.find('[data-test="retry"]').trigger('click')
        await flushPromises()
        expect(w.find('.list').exists()).toBe(true)
    })

    it('opens the profile page with a return link instead of the drawer when detailRoute is set', async () => {
        getSchema.mockResolvedValue(schema())
        nav.route = reactive({ query: { status: 'active' }, fullPath: '/helpdesk/organizations?status=active' })
        const w = await mountPage({ detailRoute: 'helpdesk-organization' })
        await w.find('.row').trigger('click')
        expect(nav.pushed).toEqual([
            {
                name: 'helpdesk-organization',
                params: { uuid: '0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40' },
                query: { from: '/helpdesk/organizations?status=active' },
            },
        ])
        expect(w.find('.detail').exists()).toBe(false)
        expect(nav.replaced).toEqual([])
    })

    it('opens the detail drawer for a list row', async () => {
        getSchema.mockResolvedValue(schema())
        const w = await mountPage()
        expect(w.find('.detail').exists()).toBe(false)
        await w.find('.row').trigger('click')
        expect(w.find('.detail').attributes('data-uuid')).toBe('0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40')
    })

    it('reloads the list on detail changed and on the reload button', async () => {
        getSchema.mockResolvedValue(schema())
        const w = await mountPage()
        const first = w.find('.list').element
        await w.find('.row').trigger('click')
        await w.find('.changed').trigger('click')
        const second = w.find('.list').element
        expect(second).not.toBe(first)
        await w.find('[data-test="reload"]').trigger('click')
        expect(w.find('.list').element).not.toBe(second)
        expect(w.find('.detail').exists()).toBe(true)
    })

    it('closes the create drawer and reloads the list when the form saved', async () => {
        getSchema.mockResolvedValue(schema())
        const w = await mountPage()
        const first = w.find('.list').element
        await w.find('[data-test="create"]').trigger('click')
        expect(w.find('.form').attributes('data-msg')).toBe('text.x.created')
        await w.find('.saved').trigger('click')
        expect(w.find('.form').exists()).toBe(false)
        expect(w.find('.list').element).not.toBe(first)
    })

    it('resourcePage() passes its props through to the page', async () => {
        getSchema.mockResolvedValue(schema())
        const { resourcePage } = await import('@/components/resource/load-resource-page')
        const Comp = await resourcePage({ resource: 'a/b', title: 'nav.t' })()
        const w = mount<any>(Comp as any, { global: { stubs } })
        await flushPromises()
        expect(getSchema).toHaveBeenCalledWith('a', 'b')
        expect(w.find('.list').exists()).toBe(true)
    })

    it('takes the header labels from schema.list.labels', async () => {
        getSchema.mockResolvedValue(
            schema({ list: { columns: [], labels: { title: 'nav.y.title', subtitle: 'text.y.sub', create: 'text.y.new', reload: 'nav.y.reload' } } }),
        )
        const w = await mountPage({ subtitle: '' })
        expect(w.find('[data-test="create"]').text()).toBe('text.y.new')
        expect(w.find('[data-test="reload"]').text()).toBe('nav.y.reload')
        expect(w.find('.bc').text()).toContain('text.y.sub')
    })

    it('falls back to the generic labels and route subtitle without schema labels', async () => {
        getSchema.mockResolvedValue(schema())
        const w = await mountPage({ subtitle: 'text.route.sub' })
        expect(w.find('[data-test="create"]').text()).toBe('text.resource.create')
        expect(w.find('[data-test="reload"]').text()).toBe('text.resource.reload')
        expect(w.find('.bc').text()).toContain('text.route.sub')
    })

    it('shows no create button when the schema grants no create form', async () => {
        getSchema.mockResolvedValue(schema({ form: null, list: { columns: [], labels: { create: 'text.y.new' } } }))
        const w = await mountPage()
        expect(w.find('[data-test="create"]').exists()).toBe(false)
    })

    it('puts the parent link before the page title from list.breadcrumbParent', async () => {
        getSchema.mockResolvedValue(schema({ list: { columns: [], breadcrumbParent: { route: 'helpdesk', label: 'nav.a.helpdesk' } } }))
        const w = await mountPage({ title: 'nav.page' })
        expect(JSON.parse(w.find('.bc').attributes('data-items') as string)).toEqual([
            { title: 'nav.a.helpdesk', text: 'nav.a.helpdesk', name: 'helpdesk' },
            { text: 'nav.page' },
        ])
    })

    it('renders the breadcrumb parent icon when the schema gives one', async () => {
        getSchema.mockResolvedValue(
            schema({ list: { columns: [], breadcrumbParent: { route: 'helpdesk', label: 'nav.a.helpdesk', icon: 'help-circle-outline' } } }),
        )
        const w = await mountPage({ title: 'nav.page' })
        expect(JSON.parse(w.find('.bc').attributes('data-items') as string)[0]).toEqual({
            icon: 'help-circle-outline',
            title: 'nav.a.helpdesk',
            text: 'nav.a.helpdesk',
            name: 'helpdesk',
        })
    })

    it('keeps the single dashboard-icon item without a breadcrumb parent', async () => {
        getSchema.mockResolvedValue(schema())
        const items = JSON.parse((await mountPage({ title: 'nav.page' })).find('.bc').attributes('data-items') as string)
        expect(items).toEqual([{ icon: 'view-dashboard-outline', title: 'nav.page', text: 'nav.page' }])
    })

    it('titles the detail drawer from detail.labels.title with the record name', async () => {
        getSchema.mockResolvedValue(schema({ detail: { labels: { title: 'text.y.drawer' } } }))
        const w = await mountPage({ title: 'nav.page' })
        expect(w.find('.row').exists()).toBe(true)
        await w.find('.row').trigger('click')
        expect(w.find('.drawer').attributes('data-title')).toBe('text.y.drawer:{"name":""}')
        await w.find('.loaded').trigger('click')
        expect(w.find('.drawer').attributes('data-title')).toBe('text.y.drawer:{"name":"Loaded Co"}')
    })

    it('titles the detail drawer with the page title when the schema has no detail labels', async () => {
        getSchema.mockResolvedValue(schema())
        const w = await mountPage({ title: 'nav.page' })
        await w.find('.row').trigger('click')
        expect(w.find('.drawer').attributes('data-title')).toBe('nav.page')
    })

    it('shows the create title, hint and submit label from form.labels', async () => {
        const base = schema()
        getSchema.mockResolvedValue(
            schema({ form: { ...base.form, labels: { title: 'text.y.new_title', hint: 'text.y.new_hint', submit: 'text.y.create', required: 'text.y.req' } } }),
        )
        const w = await mountPage()
        await w.find('[data-test="create"]').trigger('click')
        expect(w.find('.drawer').attributes('data-title')).toBe('text.y.new_title')
        expect(w.find('[data-test="create-hint"]').text()).toBe('text.y.new_hint')
        expect(w.find('.form').attributes('data-submit')).toBe('text.y.create')
        expect(w.find('.form').attributes('data-required')).toBe('text.y.req')
    })

    it('falls back to the create label as drawer title and shows no hint without form.labels', async () => {
        getSchema.mockResolvedValue(schema({ list: { columns: [], labels: { create: 'text.y.new' } } }))
        const w = await mountPage()
        await w.find('[data-test="create"]').trigger('click')
        expect(w.find('.drawer').attributes('data-title')).toBe('text.y.new')
        expect(w.find('[data-test="create-hint"]').exists()).toBe(false)
        expect(w.find('.form').attributes('data-submit')).toBe('')
    })

    it('opens the detail of a newly created record', async () => {
        getSchema.mockResolvedValue(schema({ detail: { labels: { title: 'text.y.drawer' } } }))
        const w = await mountPage()
        await w.find('[data-test="create"]').trigger('click')
        await w.find('.saved-new').trigger('click')
        expect(w.find('.detail').attributes('data-uuid')).toBe('5c1a9e22-3b7d-4e0f-8a16-9d4f2c6b7e18')
        expect(w.find('.drawer').attributes('data-title')).toBe('text.y.drawer:{"name":"Fresh"}')
    })

    const updatable = () =>
        schema({
            detail: { labels: { title: 'text.y.drawer' } },
            form: {
                create: { endpoint: { method: 'POST', path: '/api/v2/admin/x/items' }, fields: [] },
                update: { endpoint: { method: 'PUT', path: '/api/v2/admin/x/items/:uuid' }, fields: [], success_message: 'text.x.updated' },
            },
        })

    it('opens the update form prefilled from the record when the detail asks to edit', async () => {
        getSchema.mockResolvedValue(updatable())
        const w = await mountPage()
        await w.find('.row').trigger('click')
        expect(w.find('.form').exists()).toBe(false)
        await w.find('.edit').trigger('click')
        const form = w.find('.form')
        expect(form.attributes('data-method')).toBe('PUT')
        expect(form.attributes('data-path')).toBe('/api/v2/admin/x/items/:uuid')
        expect(form.attributes('data-name')).toBe('Acme')
        expect(form.attributes('data-msg')).toBe('text.x.updated')
    })

    it('closes the edit drawer, reloads the list and the record after a save', async () => {
        getSchema.mockResolvedValue(updatable())
        const w = await mountPage()
        await w.find('.row').trigger('click')
        const list = w.find('.list').element
        const detail = w.find('.detail').element
        await w.find('.edit').trigger('click')
        await w.find('.saved').trigger('click')
        expect(w.find('.form').exists()).toBe(false)
        expect(w.find('.list').element).not.toBe(list)
        expect(w.find('.detail').element).not.toBe(detail)
        expect(w.find('.detail').attributes('data-uuid')).toBe('0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40')
    })

    it('closes the detail drawer and reloads the list when the detail asks to close', async () => {
        getSchema.mockResolvedValue(schema())
        const w = await mountPage()
        await w.find('.row').trigger('click')
        const list = w.find('.list').element
        await w.find('.close').trigger('click')
        expect(w.find('.detail').exists()).toBe(false)
        expect(w.find('.list').element).not.toBe(list)
    })
})

describe('ResourcePage record in the address', () => {
    const UUID = '0d6f4c1e-8a3b-4f6e-9d2a-1b7c5e9a3f40'

    beforeEach(() => {
        vi.resetAllMocks()
        nav.route = reactive({ query: {} })
        nav.replaced = []
        getSchema.mockResolvedValue(schema())
    })

    it('writes query.record with replace when a row opens and keeps the list filters', async () => {
        nav.route.query = { q: 'acme', status: 'active' }
        const w = await mountPage()
        await w.find('.row').trigger('click')
        expect(nav.replaced).toEqual([{ query: { q: 'acme', status: 'active', record: UUID } }])
    })

    it('reopens the drawer from a reload or shared link', async () => {
        nav.route.query = { record: UUID.toUpperCase() }
        const w = await mountPage()
        expect(w.find('.detail').attributes('data-uuid')).toBe(UUID)
    })

    it('ignores a record that is not a uuid', async () => {
        nav.route.query = { record: 'not-a-uuid' }
        const w = await mountPage()
        expect(w.find('.detail').exists()).toBe(false)
    })

    it('removes only record when the drawer closes', async () => {
        nav.route.query = { q: 'acme', record: UUID }
        const w = await mountPage()
        await w.find('.close').trigger('click')
        expect(nav.replaced.at(-1)).toEqual({ query: { q: 'acme' } })
        expect(w.find('.detail').exists()).toBe(false)
    })
})
