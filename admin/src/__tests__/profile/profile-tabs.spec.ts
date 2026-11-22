import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onUnmounted } from 'vue'

const state = vi.hoisted(() => ({
    tabs: [] as any[],
    inactive: [] as string[],
    allowed: (_rule: string) => true,
    // Reactive like the real route; `setQuery` replaces its content.
    query: {} as Record<string, string>,
    replace: vi.fn(),
}))

vi.mock('@/config/admin-modules', () => ({
    get adminProfileTabs() {
        return state.tabs
    },
}))
vi.mock('@/hooks/useActiveModules', () => ({
    isModuleActive: (name?: string) => !name || !state.inactive.includes(name),
}))
vi.mock('@/mixins', () => ({ rule: (name: string) => state.allowed(name) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', async () => {
    const { reactive } = await import('vue')
    state.query = reactive(state.query)
    return {
        useRoute: () => ({ query: state.query }),
        useRouter: () => ({ replace: state.replace }),
    }
})

import ProfileTabs from '@/components/profile/profile-tabs.vue'

const stubs = {
    'i-button': {
        emits: ['click'],
        template:
            '<button type="button" class="retry" @click="$emit(\'click\', $event)"><slot /></button>',
    },
    'a-alert': {
        props: ['message'],
        template: '<div role="alert">{{ message }}<slot name="action" /></div>',
    },
    'a-skeleton': { template: '<div class="skeleton" />' },
    'a-tabs': {
        props: ['activeKey'],
        emits: ['change'],
        template: '<div class="tabs" :data-active="activeKey"><slot /></div>',
    },
    'a-tab-pane': {
        props: ['tab'],
        template:
            '<button class="tab" type="button" :data-tab="tab" @click="$parent.$emit(\'change\', $attrs[\'data-key\'])"><slot /></button>',
    },
}

const setQuery = (query: Record<string, string>) => {
    Object.keys(state.query).forEach((key) => delete state.query[key])
    Object.assign(state.query, query)
}

const unmounted = vi.fn()
const seen: any[] = []
const body = (label: string) =>
    defineComponent({
        props: { uuid: { type: String, default: '' } },
        setup(props) {
            seen.push({ label, uuid: props.uuid })
            onUnmounted(() => unmounted(label))
            return () => h('div', { class: 'body', 'data-body': label })
        },
    })

const entry = (name: string, over: Record<string, unknown> = {}) => ({
    target: 'customer',
    name,
    text: `text.${name}`,
    module: 'Helpdesk',
    rule: 'helpdesk.agent',
    load: () => Promise.resolve({ __esModule: true, default: body(name) }),
    ...over,
})

const mountTabs = async (props: Record<string, unknown> = {}) => {
    const wrapper = mount(ProfileTabs, {
        props: { target: 'customer', uuid: 'u-1', ...props },
        global: { stubs },
    })
    await flushPromises()
    return wrapper
}
const bodies = (w: any) => w.findAll('.body').map((b: any) => b.attributes('data-body'))

describe('ProfileTabs', () => {
    beforeEach(() => {
        state.tabs = [entry('a'), entry('b')]
        state.inactive = []
        state.allowed = () => true
        setQuery({})
        state.replace.mockReset()
        unmounted.mockReset()
        seen.length = 0
    })

    it('renders nothing when no tab matches', async () => {
        state.tabs = []
        expect((await mountTabs()).html()).toBe('<!--v-if-->')
        state.tabs = [entry('a', { target: 'user' })]
        expect((await mountTabs()).find('.tabs').exists()).toBe(false)
    })

    it('keeps only tabs of the target, of an active module and permitted by the rule', async () => {
        state.tabs = [
            entry('a'),
            entry('other', { target: 'user' }),
            entry('off', { module: 'Gone' }),
            entry('denied', { rule: 'x.admin' }),
            entry('b'),
        ]
        state.inactive = ['Gone']
        state.allowed = (name) => name !== 'x.admin'

        const w = await mountTabs()

        expect(w.findAll('.tab').map((t) => t.attributes('data-tab'))).toEqual(['text.a', 'text.b'])
    })

    it('opens the first tab and hands it only the uuid', async () => {
        const w = await mountTabs()

        expect(bodies(w)).toEqual(['a'])
        expect(seen).toEqual([{ label: 'a', uuid: 'u-1' }])
    })

    it('shows built-in tabs first', async () => {
        const builtIn = { name: 'overview', text: 'text.overview', component: body('overview') }

        const w = await mountTabs({ extra: [builtIn] })

        expect(w.findAll('.tab').map((t) => t.attributes('data-tab'))).toEqual([
            'text.overview',
            'text.a',
            'text.b',
        ])
        expect(bodies(w)).toEqual(['overview'])
    })

    it('reads the active tab from query.tab and ignores an unknown value', async () => {
        setQuery({ tab: 'b' })
        expect(bodies(await mountTabs())).toEqual(['b'])

        setQuery({ tab: 'nope' })
        expect(bodies(await mountTabs())).toEqual(['a'])
    })

    it('writes the tab with router.replace, keeps other keys, and unmounts the previous body', async () => {
        setQuery({ page: '2' })
        const w = await mountTabs()

        await w.findAll('.tab')[1].trigger('click')

        expect(state.replace).toHaveBeenCalledWith({ query: { page: '2', tab: 'b' } })
        // The route is mocked, so follow what the router would do.
        setQuery({ page: '2', tab: 'b' })
        await flushPromises()
        expect(bodies(w)).toEqual(['b'])
        expect(unmounted).toHaveBeenCalledWith('a')
    })

    it('shows an error with retry when the lazy import fails, and retry loads the tab', async () => {
        let fail = true
        state.tabs = [
            entry('a', {
                load: () =>
                    fail
                        ? Promise.reject(new Error('chunk'))
                        : Promise.resolve({ __esModule: true, default: body('a') }),
            }),
        ]
        const w = await mountTabs()

        expect(w.find('[role="alert"]').text()).toContain('text.profile.tab_load_failed')
        expect(w.find('.body').exists()).toBe(false)

        fail = false
        await w.find('.retry').trigger('click')
        await flushPromises()

        expect(w.find('[role="alert"]').exists()).toBe(false)
        expect(bodies(w)).toEqual(['a'])
    })
})
