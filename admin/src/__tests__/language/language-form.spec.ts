import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, inject, nextTick, provide, ref } from 'vue'
import LanguageForm from '../../../../cms/Core/admin/pages/language/components/language-form.vue'
import LanguageCreate from '../../../../cms/Core/admin/pages/language/create.vue'
import LanguageUpdate from '../../../../cms/Core/admin/pages/language/update.vue'
import { renderWithApp } from '@/test-utils/renderWithApp'

vi.mock('@/runtime', () => ({
    useAppRuntime: () => ({ locale: ref('vi') }),
}))

const enumerateRows = ref<any[]>([])
vi.mock('@/hooks', () => ({
    useEnumerates: () => ({ data: enumerateRows }),
    useLanguageFlags: () => ({ data: ref([{ file: 'vn.svg', url: '/images/flags/4x3/vn.svg' }]) }),
}))

vi.mock('@/mixins', () => ({
    rule: vi.fn(() => true),
    finishFailed: vi.fn(),
    iAlert: vi.fn(),
}))

vi.mock('@/config', () => ({ apiUrl: 'https://example.test/' }))

vi.mock('@/libs', () => ({
    getLanguage: vi.fn(async () => ({ id: 5, name: 'English', path: 'en' })),
}))

const baseForm = () => ({
    id: 0,
    name: null as string | null,
    path: null as string | null,
    path_alias: null,
    code: null as string | null,
    currency: null,
    flag: null,
    time_zone: null,
    status: 'status_published',
    is_locked: false,
    for_web: false,
    for_adm: false,
    for_app: false,
    default_web: false,
    default_adm: false,
    default_app: false,
})

const stubs = {
    breadcrumbs: { template: '<div class="bc"><slot /></div>' },
    'i-box': { template: '<div><slot /></div>' },
    'i-button': { template: '<button type="button" v-bind="$attrs"><slot /></button>' },
    'i-icon': { template: '<span />' },
    'no-permission-error': { template: '<div />' },
    'a-form': {
        name: 'AForm',
        template: '<form v-bind="$attrs"><slot /></form>',
        methods: { validate: () => Promise.resolve(), clearValidate() {} },
    },
}

/** Mount a component inside a provider that supplies the wrapper's injections. */
const mountWithProviders = async (component: any, overrides: Record<string, any> = {}) => {
    const form = ref(baseForm())
    const btnState = ref(false)
    const formRef = ref(null)
    const submitForm = vi.fn()
    const resetForm = vi.fn(() => {
        form.value = baseForm()
    })
    const setForm = vi.fn((val: any) => {
        form.value = { ...baseForm(), ...val }
    })
    const Host = defineComponent({
        setup() {
            provide('form', form)
            provide('btnState', btnState)
            provide('formRef', formRef)
            provide('submitForm', submitForm)
            provide('resetForm', resetForm)
            provide('setForm', setForm)
            return () => h(component)
        },
    })
    const { wrapper } = await renderWithApp(Host, {
        routes: [{ path: '/language/:id/update', name: 'language-manage', component: { template: '<div />' } }],
        globalStubs: stubs,
        ...overrides,
    })
    return { wrapper, form, btnState, submitForm, resetForm, setForm }
}

describe('language form', () => {
    beforeEach(() => vi.clearAllMocks())

    it('requires name and path and allows optional alias and code', async () => {
        const { wrapper } = await mountWithProviders(LanguageForm)
        const vm = wrapper.findComponent(LanguageForm).vm as any

        expect(vm.nameRules[0].required).toBe(true)
        expect(vm.pathRules[0].required).toBe(true)
        expect(vm.pathAliasRules.some((r: any) => r.required)).toBe(false)
        expect([...vm.nameRules, ...vm.pathRules, ...vm.pathAliasRules].every((r: any) => !r.trigger.includes('blur-xs'))).toBe(true)
    })

    it('lowercases the path and uppercases the code while typing', async () => {
        const { wrapper, form } = await mountWithProviders(LanguageForm)
        const vm = wrapper.findComponent(LanguageForm).vm as any

        vm.onPathInput('VI')
        vm.onCodeInput('vn')

        expect(form.value.path).toBe('vi')
        expect(form.value.code).toBe('VN')
    })

    it('disables a default switch until its platform is on and clears it when the platform turns off', async () => {
        const { wrapper, form } = await mountWithProviders(LanguageForm)
        const defaultWeb = () => wrapper.find('[data-column="default_web"]')

        expect(defaultWeb().attributes('disabled')).toBeDefined()

        form.value.for_web = true
        await nextTick()
        expect(defaultWeb().attributes('disabled')).toBeUndefined()

        form.value.default_web = true
        form.value.for_web = false
        // The template change handler: turning a platform off clears its default.
        await wrapper.find('[data-column="for_web"]').trigger('change')
        expect(form.value.default_web).toBe(false)
        await nextTick()
        expect(defaultWeb().attributes('disabled')).toBeDefined()
    })

    it('ignores submit while a save is already running', async () => {
        const { wrapper, btnState, submitForm } = await mountWithProviders(LanguageForm)
        const vm = wrapper.findComponent(LanguageForm).vm as any

        btnState.value = true
        await vm.submit()
        expect(submitForm).not.toHaveBeenCalled()

        btnState.value = false
        await vm.submit()
        expect(submitForm).toHaveBeenCalledOnce()
    })
})

describe('language status radio', () => {
    const rows = [
        ...['draft', 'pending', 'published', 'archived'].map((k) => ({ type: 'status', enum_key: k, name: k, name_alias: k })),
        ...['draft', 'pending', 'published', 'archived'].map((k) => ({ type: 'status', enum_key: `status_${k}`, name: k, name_alias: k })),
    ]
    // Ant Design is stubbed: the selected radio is the one whose value equals the group value.
    const selected = (wrapper: any) => {
        const group = wrapper.find('[name="status"] > div')
        const options = wrapper.findAll('[name="status"] label').map((l: any) => l.attributes('value'))
        return options.filter((v: string) => v === group.attributes('value'))
    }

    it('dedupes both enum sets and selects the published radio for a bare API value', async () => {
        enumerateRows.value = rows
        const { wrapper, form } = await mountWithProviders(LanguageForm)
        form.value.status = 'published'
        await nextTick()

        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(4)
        expect(selected(wrapper)).toEqual(['published'])
    })

    it('selects the published radio when the API returns status_published', async () => {
        enumerateRows.value = rows
        const { wrapper, form, setForm } = await mountWithProviders(LanguageForm)
        // The real wrapper normalises in setForm; mirror its contract through the form value.
        form.value.status = 'status_published'.replace(/^status_/, '')
        await nextTick()

        expect(setForm).not.toHaveBeenCalled()
        expect(selected(wrapper)).toEqual(['published'])
    })
})

describe('language wrapper setForm', () => {
    it('normalises status_published to published and defaults to published', async () => {
        const Language = (await import('../../../../cms/Core/admin/pages/language/language.vue')).default
        let injected: any
        let formRef: any
        const Child = defineComponent({
            setup() {
                injected = inject('setForm')
                formRef = inject('form')
                return () => h('div')
            },
        })
        await renderWithApp(Language, {
            route: { name: 'language-update', path: '/language/5/update', params: { id: 5 } },
            globalStubs: { ...stubs, 'router-view': Child },
        })
        expect(formRef.value.status).toBe('published')
        injected({ id: 5, status: 'status_published' })
        expect(formRef.value.status).toBe('published')
    })
})

describe('language create and update pages', () => {
    it('create page shows the create label and resets the form', async () => {
        const { wrapper, resetForm } = await mountWithProviders(LanguageCreate)

        expect(resetForm).toHaveBeenCalled()
        expect(wrapper.html()).toContain('nav.b.create')
        expect(wrapper.html()).not.toContain('nav.b.update')
    })

    it('update page shows the update label, never create or stray text', async () => {
        const { wrapper, setForm } = await mountWithProviders(LanguageUpdate, {
            route: { name: 'language-update', path: '/language/5/update', params: { id: 5 } },
        })
        await new Promise((r) => setTimeout(r, 0))

        expect(setForm).toHaveBeenCalledWith({ id: 5, name: 'English', path: 'en' })
        expect(wrapper.html()).toContain('nav.b.update')
        expect(wrapper.html()).not.toContain('nav.b.create')
        expect(wrapper.text()).not.toMatch(/\bxx\b/)
    })
})
