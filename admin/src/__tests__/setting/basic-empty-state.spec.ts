import { defineComponent, h, nextTick, shallowRef } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ajax from '@/ajax'
import Setting from '@/pages/setting/setting.vue'
import SettingAds from '../../../../cms/Core/admin/pages/setting/ads.vue'
import SettingBasic from '../../../../cms/Core/admin/pages/setting/basic.vue'
import SettingContact from '../../../../cms/Core/admin/pages/setting/contact.vue'
import SettingEmail from '../../../../cms/Core/admin/pages/setting/email.vue'
import SettingSocial from '../../../../cms/Core/admin/pages/setting/social.vue'
import { useSettings } from '@/hooks'

vi.mock('@/hooks', () => ({
    useSettings: vi.fn(),
}))

vi.mock('@/runtime', () => ({
    useAppRuntime: () => ({
        languages: { value: { data: [] } },
    }),
}))

vi.mock('vue-router', () => ({
    useRoute: () => ({ name: 'setting-basic' }),
}))

vi.mock('@tanstack/vue-query', () => ({
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}))

vi.mock('@/libs', () => ({
    storeSetting: vi.fn(),
    updateSetting: vi.fn(),
}))

const passthroughStub = { template: '<div><slot /></div>' }
const groupedSettingPages = [
    ['Basic', SettingBasic],
    ['Contact', SettingContact],
    ['Social', SettingSocial],
    ['Email', SettingEmail],
    ['Ads', SettingAds],
] as const
const transitionTargets = [
    ['Contact', SettingContact, 'contact', 'contact.notify_journal_email'],
    ['Social', SettingSocial, 'social', 'social.facebook'],
    ['Email', SettingEmail, 'email', 'email.MAIL_MAILER'],
    ['Ads', SettingAds, 'ads', 'ads.header_code'],
] as const
const mountPage = (
    PageComponent: (typeof groupedSettingPages)[number][1],
    renderErrors: unknown[] = []
) => {
    const currentPage = shallowRef(PageComponent)
    const RouterViewStub = defineComponent({
        setup() {
            return () => h(currentPage.value)
        },
    })

    const wrapper = mount(Setting, {
        global: {
            config: {
                errorHandler(error) {
                    renderErrors.push(error)
                },
            },
            provide: {
                typeOption: [],
            },
            stubs: {
                Breadcrumbs: passthroughStub,
                RouterView: RouterViewStub,
                AModal: passthroughStub,
                AForm: passthroughStub,
                AFormItem: passthroughStub,
                ASelect: passthroughStub,
                ASelectOptGroup: passthroughStub,
                ASelectOption: passthroughStub,
                AInput: passthroughStub,
                ASwitch: passthroughStub,
                AButton: passthroughStub,
                ADivider: passthroughStub,
                SelectInputTranslate: passthroughStub,
                SettingItem: passthroughStub,
                SettingItems: passthroughStub,
                IIcon: true,
                ASpin: {
                    props: ['spinning'],
                    template:
                        '<div data-testid="settings-spin" :data-spinning="String(spinning)"><slot /></div>',
                },
                AEmpty: {
                    props: ['description'],
                    template: '<div data-testid="settings-empty">{{ description }}</div>',
                },
            },
        },
    })

    return Object.assign(wrapper, { currentPage })
}

const settingFixture = (groupName: string, settingKey: string) => ({
    id: 1,
    created_at: null,
    deleted_at: null,
    enumerate_id: null,
    group_name: groupName,
    input_setting: {
        description: `${settingKey}.description`,
        parent_group: null,
        priority: 0,
        required: false,
        title: settingKey,
    },
    language: null,
    language_id: null,
    name: settingKey.split('.').at(-1),
    setting_key: settingKey,
    type: 'text',
    updated_at: null,
    value: 'test-value',
})

describe('Grouped settings empty states', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        vi.mocked(useSettings).mockReturnValue({
            data: {
                value: {
                    data: [
                        {
                            id: 1,
                            group_name: 'core.ai_runtime',
                            language_id: null,
                            type: 'text',
                            setting_key: 'core.ai_runtime.providers.openai.model',
                            value: 'gpt-test',
                            input_setting: {
                                title: 'core.ai_runtime.providers.openai.model',
                                parent_group: null,
                            },
                        },
                    ],
                },
            },
            refetch: vi.fn(),
        } as ReturnType<typeof useSettings>)
        vi.mocked(ajax.get).mockResolvedValue({ data: {} })
    })

    it.each(groupedSettingPages)(
        '%s stops loading and shows an empty state when its setting groups are missing',
        async (_name, PageComponent) => {
            const wrapper = mountPage(PageComponent)

            await flushPromises()

            expect(wrapper.get('[data-testid="settings-spin"]').attributes('data-spinning')).toBe(
                'false'
            )
            expect(wrapper.get('[data-testid="settings-empty"]').text()).toContain('text.no-data')
        }
    )

    it.each(groupedSettingPages)(
        '%s keeps loading while the settings query has not resolved',
        async (_name, PageComponent) => {
            vi.mocked(useSettings).mockReturnValue({
                data: { value: undefined },
                refetch: vi.fn(),
            } as ReturnType<typeof useSettings>)

            const wrapper = mountPage(PageComponent)

            await flushPromises()

            expect(wrapper.get('[data-testid="settings-spin"]').attributes('data-spinning')).toBe(
                'true'
            )
            expect(wrapper.find('[data-testid="settings-empty"]').exists()).toBe(false)
        }
    )

    it.each(transitionTargets)(
        'switching from Basic to %s does not render setting fields before form state exists',
        async (_name, PageComponent, groupName, settingKey) => {
            vi.mocked(useSettings).mockReturnValue({
                data: shallowRef({ data: [settingFixture(groupName, settingKey)] }),
                refetch: vi.fn(),
            } as ReturnType<typeof useSettings>)
            const renderErrors: unknown[] = []
            const wrapper = mountPage(SettingBasic, renderErrors)

            await flushPromises()
            wrapper.currentPage.value = PageComponent
            await nextTick()
            await flushPromises()

            expect(renderErrors).toEqual([])
            expect(wrapper.get('[data-testid="settings-spin"]').attributes('data-spinning')).toBe(
                'false'
            )
        }
    )
})
