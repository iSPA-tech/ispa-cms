import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import LanguageManage from '../../../../cms/Core/admin/pages/language/manage.vue'
import { renderWithApp } from '@/test-utils/renderWithApp'
import { useAppRuntime } from '@/runtime'
import { rule } from '@/mixins'
import { useDeleteLanguage, useRestoreLanguage, useSwitchLanguage } from '@/hooks'

vi.mock('@/runtime', () => ({
    useAppRuntime: vi.fn(),
}))

vi.mock('@/hooks', () => ({
    useEnumerates: () => ({ data: ref([]) }),
    useDeleteLanguage: vi.fn(),
    useRestoreLanguage: vi.fn(),
    useSwitchLanguage: vi.fn(),
}))

vi.mock('@/mixins', () => ({
    rule: vi.fn(() => true),
}))

vi.mock('@/config', () => ({
    apiUrl: 'https://example.test/',
}))

const switchLanguage = vi.fn()
const deleteLanguage = vi.fn()
const restoreLanguage = vi.fn()
const refetchLanguages = vi.fn()

const languageRows = [
    {
        id: 1,
        name: 'Vietnamese',
        flag: 'vn.svg',
        for_web: true,
        for_adm: true,
        for_app: true,
        default_web: true,
        default_adm: true,
        default_app: true,
        deleted_at: null,
    },
    {
        id: 2,
        name: 'English',
        flag: 'gb.svg',
        for_web: true,
        for_adm: true,
        for_app: false,
        default_web: false,
        default_adm: false,
        default_app: false,
        deleted_at: null,
    },
    {
        id: 3,
        name: 'Removed',
        flag: 'fr.svg',
        for_web: true,
        for_adm: false,
        for_app: false,
        default_web: false,
        default_adm: false,
        default_app: false,
        deleted_at: '2026-10-01 00:00:00',
    },
]

const renderLanguageManage = (permissions = ['language.viewAny', 'language.update', 'language.create']) => {
    vi.mocked(rule).mockImplementation((code: string) => permissions.includes(code))
    return renderWithApp(LanguageManage, {
    permissions,
    routes: [
        {
            path: '/language/:id/update',
            name: 'language-update',
            component: { template: '<div />' },
        },
    ],
    global: {
        provide: {
            languageRefetch: refetchLanguages,
        },
    },
    globalStubs: {
        'a-table': {
            props: ['dataSource', 'columns'],
            computed: {
                flatColumns() {
                    return this.columns.flatMap((column) => column.children || [column])
                },
            },
            template: `
                <table>
                    <tbody>
                        <tr v-for="record in dataSource" :key="record.id">
                            <td v-for="column in flatColumns" :key="column.key">
                                <slot name="bodyCell" :column="column" :record="record" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            `,
        },
        'a-switch': {
            name: 'ASwitch',
            inheritAttrs: true,
            props: ['checked', 'disabled'],
            emits: ['update:checked', 'change'],
            template: '<button type="button" v-bind="$attrs" :disabled="disabled" />',
        },
        breadcrumbs: { template: '<div class="bc"><slot /><slot name="description" /></div>' },
        'i-box': { template: '<div><slot /></div>' },
        'i-button': {
            props: ['as', 'to'],
            template: '<button type="button" v-bind="$attrs"><slot name="icon" /><slot /></button>',
        },
        'a-tooltip': { template: '<span><slot /><slot name="title" /></span>' },
        'a-popconfirm': { name: 'APopconfirm', emits: ['confirm'], template: '<span><slot /></span>' },
        'i-icon': { template: '<span />' },
        'no-permission-error': { template: '<div />' },
    },
})
}

describe('language management defaults', () => {
    beforeEach(() => {
        switchLanguage.mockReset()
        deleteLanguage.mockReset()
        restoreLanguage.mockReset()
        refetchLanguages.mockReset()

        vi.mocked(useAppRuntime).mockReturnValue({
            languages: ref({ data: languageRows.map((row) => ({ ...row })) }),
        } as ReturnType<typeof useAppRuntime>)
        vi.mocked(useSwitchLanguage).mockReturnValue({
            mutate: switchLanguage,
        } as ReturnType<typeof useSwitchLanguage>)
        vi.mocked(useDeleteLanguage).mockReturnValue({
            mutate: deleteLanguage,
        } as ReturnType<typeof useDeleteLanguage>)
        vi.mocked(useRestoreLanguage).mockReturnValue({
            mutate: restoreLanguage,
        } as ReturnType<typeof useRestoreLanguage>)
    })

    it('lets another web-enabled language replace the current web default', async () => {
        const { wrapper } = await renderLanguageManage()

        const englishDefaultWeb = wrapper.find(
            '[data-language-id="2"][data-column="default_web"]',
        )

        expect(englishDefaultWeb.exists()).toBe(true)
        expect(englishDefaultWeb.attributes('disabled')).toBeUndefined()
    })

    it('keeps default switches disabled when the language is not used for that platform', async () => {
        const { wrapper } = await renderLanguageManage()

        const englishDefaultApp = wrapper.find(
            '[data-language-id="2"][data-column="default_app"]',
        )

        expect(englishDefaultApp.exists()).toBe(true)
        expect(englishDefaultApp.attributes('disabled')).toBeDefined()
    })

    it('refetches languages after a successful switch so old defaults turn off', async () => {
        switchLanguage.mockImplementation((_payload, options) => options.onSuccess({}))
        const { wrapper } = await renderLanguageManage()

        wrapper.vm.quickUpdate(2, 'default_web', true)

        expect(switchLanguage).toHaveBeenCalledWith(
            { id: 2, col: 'default_web', value: true },
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        )
        expect(refetchLanguages).toHaveBeenCalledOnce()
    })

    it('sends the checked value emitted by the platform switch', async () => {
        const { wrapper } = await renderLanguageManage()
        const vietnameseForWeb = wrapper
            .findAllComponents({ name: 'ASwitch' })
            .find(
                (component) =>
                    component.attributes('data-language-id') === '1' &&
                    component.attributes('data-column') === 'for_web',
            )

        expect(vietnameseForWeb).toBeDefined()

        vietnameseForWeb?.vm.$emit('change', false)

        expect(switchLanguage).toHaveBeenCalledWith(
            { id: 1, col: 'for_web', value: false },
            expect.any(Object),
        )
    })

    it('uses the fixed flag column key so the flag image renders', async () => {
        const { wrapper } = await renderLanguageManage()

        const img = wrapper.find('img[alt="Vietnamese"]')

        expect(img.exists()).toBe(true)
        expect(img.attributes('src')).toBe('https://example.test/images/flags/4x3/vn.svg')
    })

    it('labels each platform switch for assistive technology', async () => {
        const { wrapper } = await renderLanguageManage()

        const sw = wrapper.find('[data-language-id="1"][data-column="for_web"]')

        expect(sw.attributes('aria-label')).toBe('text.language.aria_use_for')
    })

    it('confirms before deleting a live row and refetches on success', async () => {
        deleteLanguage.mockImplementation((_id, options) => options.onSuccess({}))
        const { wrapper } = await renderLanguageManage()

        const confirms = wrapper.findAllComponents({ name: 'APopconfirm' })
        // Rows 1 and 2 are live (delete confirm), row 3 is deleted (restore confirm).
        expect(confirms).toHaveLength(3)
        confirms[0].vm.$emit('confirm')

        expect(deleteLanguage).toHaveBeenCalledWith(1, expect.any(Object))
        expect(refetchLanguages).toHaveBeenCalledOnce()
    })

    it('offers only restore for a soft-deleted row and dims it', async () => {
        restoreLanguage.mockImplementation((_id, options) => options.onSuccess({}))
        const { wrapper } = await renderLanguageManage()

        const confirms = wrapper.findAllComponents({ name: 'APopconfirm' })
        confirms[2].vm.$emit('confirm')

        expect(restoreLanguage).toHaveBeenCalledWith(3, expect.any(Object))
        expect(refetchLanguages).toHaveBeenCalledOnce()
        expect(
            wrapper.find('[data-language-id="3"][data-column="for_web"]').attributes('disabled'),
        ).toBeDefined()
    })

    it('hides the Add action without language.create', async () => {
        const withCreate = await renderLanguageManage()
        expect(withCreate.wrapper.find('.bc button').exists()).toBe(true)

        const without = await renderLanguageManage(['language.viewAny', 'language.update'])
        expect(without.wrapper.find('.bc button').exists()).toBe(false)
    })

    it('renders the no-permission state without language.viewAny', async () => {
        const { wrapper } = await renderLanguageManage([])

        expect(wrapper.find('table').exists()).toBe(false)
    })
})
