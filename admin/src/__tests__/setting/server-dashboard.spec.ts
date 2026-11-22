import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('vue-i18n', () => ({
    useI18n: () => ({ t: (k: string) => k, locale: { value: 'en' } }),
}))

const payload = {
    server_info: {
        php_version: '8.3.4 (Supports 64bit values: Yes)',
        php_memory_limit: '512M',
        imagick_available: 'No',
        suhosin_installed: 'No',
    },
    database_info: { database_extension: 'mysqli' },
    directories_info: { database_size: '1 GB', storage_directory_size: '2 GB' },
    composer_info: { composer_lock_exists: 'Yes', composer_json_exists: 'Yes', installed_packages: { 'a/b': '1.0' } },
    htaccess_info: { htaccess_exists: 'Yes' },
    modules_info: { modules_enabled_count: 5, modules_disabled_count: 2, modules_list: {} },
    modules_error: 'boom',
}

vi.mock('@/ajax', () => ({ default: { get: vi.fn(async () => ({ data: { data: payload } })) } }))

const IStatTile = defineComponent({
    props: { label: String, value: [Number, String], tone: String, loading: Boolean },
    setup: () => () => h('div', { 'data-tile': '1' }),
})
const IButton = defineComponent({
    props: ['variant', 'size', 'loading'],
    setup: (_p, { slots, attrs }) => () => h('button', { ...attrs }, slots.default?.()),
})
const passthrough = (tag: string) =>
    defineComponent({ setup: (_p, { slots }) => () => h(tag, slots.default?.()) })

const mountPage = async () => {
    const Page = (await import('../../../../cms/Core/admin/pages/setting/dashboard.vue')).default
    const w = mount(Page, {
        global: {
            mocks: { $t: (k: string) => k },
            stubs: {
                breadcrumbs: passthrough('div'),
                'i-button': IButton,
                'i-stat-tile': IStatTile,
                'i-box': passthrough('div'),
                'i-icon': true,
                'a-collapse': true,
                'a-collapse-panel': true,
                'a-badge': true,
                'a-result': true,
            },
        },
    })
    await flushPromises()
    return w
}

describe('server dashboard', () => {
    it('renders six tiles in the design-system grid with health tones', async () => {
        const w = await mountPage()
        expect(w.get('[data-testid="server-kpi-grid"]').classes()).toEqual(
            expect.arrayContaining(['grid-cols-2', 'gap-3', 'sm:grid-cols-3', 'xl:grid-cols-6'])
        )
        const tiles = w.findAllComponents(IStatTile)
        expect(tiles).toHaveLength(6)
        const byLabel = Object.fromEntries(tiles.map((t) => [t.props('label'), t.props()]))
        expect(byLabel['text.server_dashboard.php_version'].value).toBe('8.3.4')
        expect(byLabel['text.server_dashboard.enabled_modules'].tone).toBe('success')
        expect(byLabel['text.server_dashboard.disabled_modules'].tone).toBe('default')
    })

    it('shows failing checks first and uses an i-button reload', async () => {
        const w = await mountPage()
        const items = w.findAll('[data-testid="server-health-item"]')
        expect(items.length).toBe(2)
        expect(w.get('[data-testid="server-health-strip"]').text()).toContain('boom')
        expect(w.findComponent(IButton).props('variant')).toBe('default')
        expect(w.html()).not.toContain('a-button')
    })

    it('groups detail rows into boxes', async () => {
        const w = await mountPage()
        for (const k of ['php', 'database', 'storage', 'composer', 'htaccess'])
            expect(w.find(`[data-testid="server-box-${k}"]`).exists()).toBe(true)
    })
})
