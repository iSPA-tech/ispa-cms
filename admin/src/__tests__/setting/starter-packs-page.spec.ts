import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import StarterPacks from '@core-admin/pages/setting/starter-packs.vue'
import { fetchStarterPack, fetchStarterPacks, hasNothingToDo, irreversibleModules, matchMessage, packUrl } from '@/libs/starter-packs'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        locale: { value: 'en' },
        t: (key: string, params?: Record<string, string>) => (params ? `${key}|${Object.values(params).join(',')}` : key),
        te: () => true,
    }),
}))

const stubs = {
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}<slot name="action" /></div>' },
    'a-button': { emits: ['click'], template: '<button @click="$emit(\'click\', $event)"><slot /></button>' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-skeleton': true,
    'a-empty': { props: ['description'], template: '<div>{{ description }}</div>' },
}

const list = [
    { key: 'help-center', name: 'Help center', description: 'Docs', version: '1.0.0', modules: ['Helpdesk'], applied: false },
    { key: 'blog', name: 'Blog', description: 'Posts', version: '1.2.0', modules: ['Content'], applied: true },
]
const plan = (over: Record<string, unknown> = {}) => ({
    pack_key: 'help-center', can_apply: true, has_changes: true, blockers: [], warnings: [],
    modules: [{ name: 'Helpdesk', version: '1.0.0', state: 'to_install', irreversible: ['2026_create_x'] }],
    config: { sections: { settings: { counts: { create: 3, update: 1 } } } },
    roles: { created: ['Support agent'] }, workflows: { created: [] },
    content: [{ module: 'Helpdesk', counts: { articles: 4 } }],
    ...over,
})
const detail = (p: unknown) => ({ data: { data: { key: 'help-center', name: 'Help center', can_apply: true, plan: p } } })

const mountPage = async () => {
    const w = mount(StarterPacks, { global: { stubs } })
    await flushPromises()
    return w
}
const id = (w: ReturnType<typeof mount>, x: string) => w.find(`[data-testid="${x}"]`)

beforeEach(() => {
    ajaxMock.get.mockReset().mockImplementation((url: string) =>
        Promise.resolve(url === 'starter-packs' ? { data: { data: list } } : detail(plan()))
    )
})

describe('starter-packs lib', () => {
    it('builds paths and reads rows', async () => {
        expect(packUrl('help-center')).toBe('starter-packs/help-center')
        expect(await fetchStarterPacks()).toHaveLength(2)
        expect((await fetchStarterPack('help-center')).plan.pack_key).toBe('help-center')
    })
    it('matches planner messages and leaves unknown ones', () => {
        expect(matchMessage('Pack version 1.0.0 is older than version 2.0.0 already applied to this site.')?.key).toBe('blocker_older')
        expect(matchMessage('Module Helpdesk 1.0 does not meet the pack\'s constraint ">=2".')?.params.name).toBe('Helpdesk')
        const missing = matchMessage("45 setting(s) of the configuration cannot be added because the module's default settings are not installed on this site yet.")
        expect(missing?.key).toBe('warning_settings_missing')
        expect(missing?.params.count).toBe('45')
        expect(matchMessage('something new')).toBeNull()
    })
    it('derives flags', () => {
        expect(hasNothingToDo({ has_changes: false, blockers: [] })).toBe(true)
        expect(hasNothingToDo({ has_changes: false, blockers: ['x'] })).toBe(false)
        expect(irreversibleModules(plan())).toEqual([{ name: 'Helpdesk', migrations: ['2026_create_x'] }])
    })
})

describe('starter packs page', () => {
    it('shows a loading state first', async () => {
        ajaxMock.get.mockReturnValue(new Promise(() => {}))
        const w = mount(StarterPacks, { global: { stubs } })
        await w.vm.$nextTick()
        expect(id(w, 'starter-packs-loading').exists()).toBe(true)
    })
    it('shows an error with retry that reloads', async () => {
        ajaxMock.get.mockReset().mockRejectedValueOnce(new Error('x')).mockResolvedValue({ data: { data: list } })
        const w = await mountPage()
        expect(id(w, 'starter-packs-error').exists()).toBe(true)
        await id(w, 'starter-packs-retry').trigger('click')
        await flushPromises()
        expect(id(w, 'pack-card-help-center').exists()).toBe(true)
    })
    it('shows an empty state', async () => {
        ajaxMock.get.mockReset().mockResolvedValue({ data: { data: [] } })
        const w = await mountPage()
        expect(id(w, 'starter-packs-empty').exists()).toBe(true)
    })
    it('lists cards with the applied badge and no ids', async () => {
        const w = await mountPage()
        expect(id(w, 'pack-card-help-center').text()).toContain('Help center')
        expect(id(w, 'pack-applied-blog').exists()).toBe(true)
        expect(id(w, 'pack-applied-help-center').exists()).toBe(false)
        expect(id(w, 'pack-detail').exists()).toBe(false)
    })
    it('previews modules, irreversible warning, config, roles and content', async () => {
        const w = await mountPage()
        await id(w, 'pack-card-help-center').trigger('click')
        await flushPromises()
        expect(ajaxMock.get).toHaveBeenCalledWith('starter-packs/help-center', { silent: true, headers: { 'X-Locale': 'en' } })
        expect(id(w, 'pack-modules').text()).toContain('Helpdesk')
        expect(id(w, 'pack-modules').text()).toContain('text.starter_pack.state_to_install')
        expect(id(w, 'pack-irreversible').exists()).toBe(true)
        expect(id(w, 'pack-config').text()).toContain('text.starter_pack.will_create|3')
        // A pack never changes existing values: the differing item is reported as kept, never as "change".
        expect(id(w, 'pack-config').text()).toContain('text.starter_pack.will_keep|1')
        expect(id(w, 'pack-config').text()).not.toContain('will_create|3,1')
        expect(id(w, 'pack-roles').text()).toContain('Support agent')
        expect(id(w, 'pack-content').text()).toContain('text.starter_pack.count_articles')
        expect(id(w, 'pack-blockers').exists()).toBe(false)
    })
    it('shows no kept note when nothing differs', async () => {
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve(url === 'starter-packs' ? { data: { data: list } } : detail(plan({ config: { sections: { settings: { counts: { create: 3, update: 0 } } } } })))
        )
        const w = await mountPage()
        await id(w, 'pack-card-help-center').trigger('click')
        await flushPromises()
        expect(id(w, 'pack-config').text()).toContain('text.starter_pack.will_create|3')
        expect(id(w, 'pack-config').text()).not.toContain('will_keep')
    })
    it('a blocked pack shows the blocker text and no apply control', async () => {
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve(url === 'starter-packs' ? { data: { data: list } } : detail(plan({ can_apply: false, blockers: ['Pack version 1.0.0 is older than version 2.0.0 already applied to this site.'] })))
        )
        const w = await mountPage()
        await id(w, 'pack-card-help-center').trigger('click')
        await flushPromises()
        expect(id(w, 'pack-blockers').text()).toContain('text.starter_pack.blocker_older')
        expect(id(w, 'pack-blockers').text()).toContain('text.starter_pack.blocked')
        expect(w.find('[data-testid="starter-pack-apply"]').exists()).toBe(false)
        expect(id(w, 'pack-nothing-to-do').exists()).toBe(false)
    })
    it('an applied pack with no changes says nothing to do', async () => {
        ajaxMock.get.mockImplementation((url: string) =>
            Promise.resolve(url === 'starter-packs' ? { data: { data: list } } : detail(plan({ has_changes: false })))
        )
        const w = await mountPage()
        await id(w, 'pack-card-blog').trigger('click')
        await flushPromises()
        expect(id(w, 'pack-nothing-to-do').text()).toContain('text.starter_pack.nothing_to_do')
    })
    it('shows a detail error with retry', async () => {
        const w = await mountPage()
        ajaxMock.get.mockReset().mockRejectedValueOnce(new Error('x')).mockResolvedValue(detail(plan()))
        await id(w, 'pack-card-help-center').trigger('click')
        await flushPromises()
        expect(id(w, 'pack-detail-error').exists()).toBe(true)
        await id(w, 'pack-detail-retry').trigger('click')
        await flushPromises()
        expect(id(w, 'pack-modules').exists()).toBe(true)
    })
})
