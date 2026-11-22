import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import StarterPacks from '@core-admin/pages/setting/starter-packs.vue'
import { applyErrorKind, modulesNeedingThirdParty } from '@/libs/starter-packs'

const ajaxMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const session = vi.hoisted(() => ({ webmaster: true }))
vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({ iAlert: vi.fn() }))
vi.mock('@/hooks/useCan', () => ({ isWebmaster: () => session.webmaster }))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        locale: { value: 'en' },
        t: (key: string, params?: Record<string, string>) => (params ? `${key}|${Object.values(params).join(',')}` : key),
        te: () => true,
    }),
}))

const stubs = {
    'a-alert': { props: ['message', 'description'], template: '<div role="alert">{{ message }} {{ description }}<slot name="description" /><slot name="action" /></div>' },
    'a-button': { emits: ['click'], template: '<button @click="$emit(\'click\', $event)"><slot /></button>' },
    'i-button': { props: ['disabled', 'loading', 'nativeType'], emits: ['click'], template: '<button :type="nativeType || \'button\'" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>' },
    'a-drawer': { props: ['open'], template: '<div v-if="open" data-testid="drawer"><slot /></div>' },
    'a-tag': { template: '<span><slot /></span>' },
    'a-skeleton': true,
    'a-empty': true,
}

/*
 * a starter pack that installs a module published by a partner shows the same confirmation box as the single
 * install (publisher, what the module adds, the tick); the apply cannot start until every box is ticked and the request
 * carries `confirm_third_party`.
 */
const ACME = { key: 'acme', type: 'partner', name: 'Acme Studio' }
const IMPACT = { permissions: ['a', 'b'], menus: 1, routes: { public: 1, admin: 2, api: 0 }, tables_created: ['gallery_items'], tables_altered: [], irreversible: false, migrations: 1 }
const list = [{ key: 'help-center', name: 'Help center', description: 'Docs', version: '1.0.0', modules: ['Helpdesk', 'Gallery'], applied: false }]
const plan = (over: Record<string, unknown> = {}) => ({
    pack_key: 'help-center', pack_version: '1.0.0', has_changes: true, blockers: [], warnings: [],
    modules: [
        { name: 'Helpdesk', version: '1.0.0', state: 'to_install', irreversible: [], third_party: null },
        { name: 'Gallery', version: '1.0.0', state: 'to_install', irreversible: [], third_party: { publisher: ACME, impact: IMPACT } },
    ],
    config: null, roles: { created: [] }, workflows: { created: [] }, content: [],
    ...over,
})
const detail = (p: unknown) => ({ data: { data: { key: 'help-center', version: '1.0.0', name: 'Help center', can_apply: true, plan: p } } })
let current: unknown

const id = (w: ReturnType<typeof mount>, x: string) => w.find(`[data-testid="${x}"]`)
const mountPage = async () => {
    const w = mount(StarterPacks, { global: { stubs } })
    await flushPromises()
    await id(w, 'pack-card-help-center').trigger('click')
    await flushPromises()
    return w
}
const err = (status: number, data: Record<string, unknown>) => Object.assign(new Error('x'), { response: { status, data } })

beforeEach(() => {
    session.webmaster = true
    current = plan()
    ajaxMock.get.mockReset().mockImplementation((url: string) => {
        if (url === 'starter-packs') return Promise.resolve({ data: { data: list } })
        if (url.endsWith('/history')) return Promise.resolve({ data: { data: [] } })
        return Promise.resolve(detail(current))
    })
    ajaxMock.post.mockReset().mockResolvedValue({ data: { data: { application_uuid: 'u1', task_uuid: 't1' } } })
})

afterEach(() => vi.clearAllMocks())

describe('starter pack partner modules', () => {
    it('lists the modules to install that a partner publishes, with publisher and impact', () => {
        expect(modulesNeedingThirdParty(plan())).toEqual([{ name: 'Gallery', details: { publisher: ACME, impact: IMPACT } }])
        expect(modulesNeedingThirdParty(plan({ modules: [{ name: 'Gallery', state: 'enabled', third_party: { publisher: ACME, impact: null } }] }))).toEqual([])
        expect(modulesNeedingThirdParty(null)).toEqual([])
    })

    it('knows the server answer that asks for the confirmation', () => {
        expect(applyErrorKind(err(422, { third_party: [{ module: 'Gallery' }] })).kind).toBe('third_party')
    })

    it('shows one confirmation box per partner module, none for an iSPA module, and a warning on the plan', async () => {
        const w = await mountPage()
        expect(id(w, 'pack-third-party').text()).toContain('Gallery')
        await id(w, 'pack-apply').trigger('click')
        const boxes = w.findAll('[data-testid="third-party-confirm"]')
        expect(boxes).toHaveLength(1)
        expect(id(w, 'apply-confirm-third-party').text()).toContain('Gallery')
        expect(boxes[0].text()).toContain('Acme Studio')
        expect(ajaxMock.post).not.toHaveBeenCalled()
    })

    it('keeps the apply off until the box is ticked, then sends confirm_third_party once', async () => {
        const w = await mountPage()
        await id(w, 'pack-apply').trigger('click')
        expect(id(w, 'apply-start').attributes('disabled')).toBeDefined()
        await id(w, 'third-party-confirm-check').setValue(true)
        expect(id(w, 'apply-start').attributes('disabled')).toBeUndefined()
        const form = id(w, 'apply-confirm')
        void form.trigger('submit')
        void form.trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).toHaveBeenCalledTimes(1)
        expect(ajaxMock.post).toHaveBeenCalledWith('starter-packs/help-center/apply', { version: '1.0.0', confirm_third_party: true }, { silent: true })
        w.unmount()
    })

    it('asks nothing extra for a pack of iSPA modules', async () => {
        current = plan({ modules: [{ name: 'Helpdesk', version: '1.0.0', state: 'to_install', irreversible: [], third_party: null }] })
        const w = await mountPage()
        await id(w, 'pack-apply').trigger('click')
        expect(id(w, 'apply-confirm-third-party').exists()).toBe(false)
        expect(id(w, 'apply-start').attributes('disabled')).toBeUndefined()
        w.unmount()
    })

    it('says the plain error when the server still asks for the confirmation', async () => {
        const w = await mountPage()
        await id(w, 'pack-apply').trigger('click')
        await id(w, 'third-party-confirm-check').setValue(true)
        ajaxMock.post.mockRejectedValueOnce(err(422, { third_party: [{ module: 'Gallery' }] }))
        void id(w, 'apply-confirm').trigger('submit')
        await flushPromises()
        expect(id(w, 'apply-error').text()).toContain('text.starter_pack.error_third_party')
        w.unmount()
    })
})
