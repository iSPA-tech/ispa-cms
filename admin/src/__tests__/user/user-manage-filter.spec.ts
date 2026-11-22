import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { nextTick, unref } from 'vue'
import { renderWithApp } from '@/test-utils/renderWithApp'
import UserManage from '../../../../cms/Core/admin/pages/user/manage.vue'
import { attachUserTags, detachUserTags } from '@/libs'
import { userTagNames } from '@/components/user-tag-text'

const rows = vi.hoisted(() => [
    { uuid: 'u-1', name: 'An', email: 'a@x.vn', roles: [], tags: [{ uuid: 't1', name: 'T1' }, { uuid: 't2', name: 'T2' }, { uuid: 't3', name: 'T3' }, { uuid: 't4', name: 'T4' }, { uuid: 't5', name: 'T5' }] },
    { uuid: 'u-2', name: 'Bình', email: 'b@x.vn', roles: [], tags: [] },
])
const useUsersByFilterMock = vi.hoisted(() => vi.fn())
const pickedKeys = vi.hoisted(() => ({ ref: null as any }))
const messages = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }))

vi.mock('@/config', () => ({
    CACHE_KEY_ACCOUNT_DATA: 'CACHE_KEY_ACCOUNT_DATA',
    CACHE_KEY_SIGN_IN_AS_META: 'CACHE_KEY_SIGN_IN_AS_META',
    COOKIE_KEY_LOCALE: 'iSpa-locale',
    COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE: 'impersonate-original-locale',
    COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN: 'impersonate-original-token',
    COOKIE_KEY_TOKEN: 'iSpa-token',
    defaultFilterParams: { page: 1, pageSize: 20 },
}))

vi.mock('@/config/cookies', () => ({
    cookie: {
        getAll: vi.fn(() => ({})),
        set: vi.fn(),
        remove: vi.fn(),
    },
}))

vi.mock('@/libs', () => ({
    deleteUser: vi.fn(),
    attachUserTags: vi.fn(),
    detachUserTags: vi.fn(),
    signInAsUser: vi.fn(),
    updateUser: vi.fn(),
    updateUserPassword: vi.fn(),
}))

vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
    objectAssignOnlyByKeyHasValue: (_target, source) => source,
    rule: () => true,
}))

vi.mock('@/hooks', async () => {
    const { ref } = await import('vue')

    return {
        useBulkSelection: (keys) => { pickedKeys.ref = keys; return { rowSelection: {} } },
        useEnumerates: () => ({ data: ref({ data: [] }) }),
        useUsersByFilter: (params, options) => {
            useUsersByFilterMock(params, options)

            return {
                data: ref({ data: { data: rows, currentPage: 1, perPage: 20, total: 137 } }),
                // A refetch takes a network round trip: resolve on the next macrotask, not synchronously.
                refetch: vi.fn(() => new Promise((resolve) => setTimeout(resolve, 0))),
                isLoading: ref(false),
                isFetching: ref(false),
            }
        },
    }
})

vi.mock('ant-design-vue', () => ({
    Form: {
        useForm: () => ({
            clearValidate: vi.fn(),
            resetFields: vi.fn(),
        }),
    },
    message: messages,
}))

vi.mock('@/components/select-input-user-role.vue', () => ({ default: { name: 'SelectInputUserRole', props: ['modelValue', 'placeholder'], emits: ['update:modelValue'], template: '<button type="button" data-testid="role-select" :data-placeholder="placeholder" @click="$emit(\'update:modelValue\', [7])" />' } }))
vi.mock('@/components/select-input-user-tag.vue', () => ({ default: { name: 'SelectInputUserTag', props: ['modelValue', 'allowCreate'], emits: ['update:modelValue'], template: '<button type="button" data-testid="tag-select" :data-create="String(allowCreate)" @click="$emit(\'update:modelValue\', [\'t-1\', \'t-2\'])" />' } }))
vi.mock('@/components/user-tag-apply-modal.vue', () => ({ default: { name: 'UserTagApplyModal', props: ['open', 'initialTags', 'count', 'userUuids', 'filter'], template: '<div data-testid="apply-modal" :data-open="String(open)" :data-initial="initialTags.join()" :data-count="count" :data-uuids="userUuids.join()" :data-filter="JSON.stringify(filter)" />' } }))

const renderManage = () =>
    renderWithApp(UserManage, {
            route: { name: 'user-manage', path: '/user/manage' },
            routes: [
                { path: '/user/create', name: 'user-create', component: { template: '<div />' } },
                { path: '/user/:uuid', name: 'user-update', component: { template: '<div />' } },
            ],
            permissions: ['user.viewAny'],
            globalStubs: {
                breadcrumbs: { template: '<div><slot /></div>' },
                'i-filter': {
                    props: ['defaults', 'schema'],
                    emits: ['submit'],
                    template: '<div data-testid="user-filter">{{ defaults.status }}<slot name="roleControl" /><slot name="tagControl" /><button type="button" data-testid="apply-filter" @click="$emit(\'submit\', { keywords: \'lan\', status: \'all\' })" /></div>',
                    methods: {
                        getValues() {
                            return this.defaults
                        },
                        applyRoute: () => {},
                    },
                },
                'i-bulk-action': {
                    props: ['modelValue', 'actions', 'baseActions'],
                    emits: ['update:modelValue'],
                    template: '<div data-testid="bulk-bar" :data-actions="actions.map((a) => a.key).join()" :data-base="baseActions.join()"><slot name="summary" /><slot name="extra-actions" :disabled="modelValue.length === 0" /></div>',
                },
                'i-button': { props: ['disabled'], template: '<button type="button" :disabled="disabled" v-bind="$attrs"><slot /></button>' },
                'a-segmented': { template: '<div data-testid="filter-match" />' },
                'i-icon': { template: '<span />' },
                'i-badge': { template: '<span><slot /></span>' },
                'i-switch': { template: '<button type="button" />' },
                'a-table': {
                    props: ['dataSource'],
                    template: '<div><div v-for="row in dataSource" :key="row.uuid"><slot name="bodyCell" :column="{ key: \'name\' }" :record="row" :index="0" /></div></div>',
                },
                'a-modal': { template: '<div />' },
                'a-form': { template: '<form><slot /></form>' },
                'a-form-item': { template: '<div><slot /></div>' },
                'a-input': { template: '<input />' },
                'a-checkbox': { template: '<input type="checkbox" />' },
                'a-tooltip': { props: ['title'], template: '<span :data-tip="title"><slot /></span>' },
                'a-dropdown': { props: ['disabled'], template: '<span data-testid="dropdown" :data-disabled="String(disabled)"><slot /><slot name="overlay" /></span>' },
                'a-menu': { template: '<ul><slot /></ul>' },
                'a-menu-item': { emits: ['click'], template: '<li data-testid="detach-option" @click="$emit(\'click\')"><slot /></li>' },
            },
        })

const pickPage = async (wrapper) => {
    pickedKeys.ref.value = ['u-1', 'u-2']
    await nextTick()
}

describe('UserManage filters', () => {
    beforeEach(() => { vi.clearAllMocks(); (detachUserTags as any).mockResolvedValue({ data: { affected: 2 } }); userTagNames.set('t-1', 'Triết'); userTagNames.set('t-2', 'Sử') })

    it('reads the single users/filter source with the default filter and shows tags under the name', async () => {
        const { wrapper } = await renderManage()

        expect(wrapper.get('[data-testid="user-filter"]').text()).toContain('all')
        expect(useUsersByFilterMock).toHaveBeenCalled()
        expect(unref(useUsersByFilterMock.mock.calls.at(-1)![0])).toMatchObject({ status: 'all', page: 1 })
        const tags = wrapper.findAll('[data-testid="row-tags"]')
        expect(tags).toHaveLength(1) // only the user with tags
        expect(tags[0].text()).toContain('T3')
        expect(tags[0].text()).not.toContain('T4')
        expect(wrapper.get('[data-testid="row-tags-more"]').text()).toBe('+2')
    })

    it('puts role and tag selects in the filter row; the tag filter never creates tags', async () => {
        const { wrapper } = await renderManage()
        const row = wrapper.get('[data-testid="user-filter"]')

        expect(row.get('[data-testid="filter-roles"]').exists()).toBe(true)
        expect(wrapper.findComponent({ name: 'SelectInputUserRole' }).props('placeholder')).toBe('Nhóm') // Vietnamese fallback, never the raw key
        expect(row.get('[data-testid="filter-tags"]').exists()).toBe(true)
        expect(wrapper.findComponent({ name: 'SelectInputUserTag' }).props('allowCreate')).toBeFalsy()
        expect(wrapper.find('[data-testid="user-tag-toolbar"]').exists()).toBe(false)
    })

    it('shows the any/all toggle only for two or more tags and applies role and tags on submit', async () => {
        const { wrapper } = await renderManage()
        expect(wrapper.find('[data-testid="filter-match"]').exists()).toBe(false)

        await wrapper.get('[data-testid="filter-roles"]').trigger('click')
        await wrapper.get('[data-testid="filter-tags"]').trigger('click')
        expect(wrapper.find('[data-testid="filter-match"]').exists()).toBe(true)
        expect(unref(useUsersByFilterMock.mock.calls.at(-1)![0])).not.toHaveProperty('tags') // not applied yet

        await wrapper.get('[data-testid="apply-filter"]').trigger('click')
        expect(unref(useUsersByFilterMock.mock.calls.at(-1)![0])).toMatchObject({ roles: [7], tags: ['t-1', 't-2'], match: 'all', q: 'lan' })
    })

    it('hides the selection bar until a row is picked', async () => {
        const { wrapper } = await renderManage()
        expect(wrapper.find('[data-testid="bulk-bar"]').exists()).toBe(false)
    })

    it('renders each chip with a tag icon and a remove button named after the tag', async () => {
        const { wrapper } = await renderManage()
        const chip = wrapper.findAll('[data-testid="row-tag"]')[0]
        expect(chip.find('span').exists()).toBe(true) // i-icon stub
        expect(chip.get('[data-testid="row-tag-remove"]').attributes('aria-label')).toBe('Gỡ tag T1')
        expect(wrapper.findAll('[data-testid="row-tag"]')).toHaveLength(3)
        expect(wrapper.get('[data-testid="row-tags-more"]').element.parentElement!.getAttribute('data-tip')).toBe('T4, T5')
        expect(wrapper.text()).not.toMatch(/text\.user-tag/)
    })

    it('the chip x removes that tag from that user at once and toasts the tag name', async () => {
        const { wrapper } = await renderManage()
        await wrapper.findAll('[data-testid="row-tag-remove"]')[1].trigger('click')
        expect(wrapper.findAll('[data-testid="row-tag"]')[1].text()).toContain('T3') // optimistic: T2 is gone already
        expect(detachUserTags).toHaveBeenCalledWith({ tag_uuids: ['t2'], user_uuids: ['u-1'] })
        await flushPromises()
        expect(messages.success).toHaveBeenCalledWith('Đã gỡ tag T2')
    })

    it('after a tag write the chips follow the refetched data, so a tag removed then re-added shows again', async () => {
        // Regression: vue-query keeps the same data reference when the server state returns to the cached one
        // (remove T2, add T2 back), so clearing overrides only on a data change left T2 hidden for good.
        const { wrapper } = await renderManage()
        await wrapper.findAll('[data-testid="row-tag-remove"]')[1].trigger('click')
        expect(wrapper.findAll('[data-testid="row-tag"]').map((c) => c.text()).join(' ')).not.toContain('T2')
        await flushPromises()
        await new Promise((resolve) => setTimeout(resolve, 5)) // let the refetch settle
        await flushPromises()
        expect(wrapper.findAll('[data-testid="row-tag"]').map((c) => c.text()).join(' ')).toContain('T2')
    })

    it('puts the chip back when removing fails', async () => {
        ;(detachUserTags as any).mockRejectedValue({ response: { data: { message: 'boom' } } })
        const { wrapper } = await renderManage()
        await wrapper.findAll('[data-testid="row-tag-remove"]')[0].trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[data-testid="row-tag"]')[0].text()).toContain('T1')
        expect(messages.error).toHaveBeenCalledWith('boom')
    })

    it('Gắn tag opens the dialog prefilled with the tags chosen in the filter', async () => {
        const { wrapper } = await renderManage()
        await wrapper.get('[data-testid="filter-tags"]').trigger('click')
        await pickPage(wrapper)
        await wrapper.get('[data-testid="tag-attach"]').trigger('click')
        const modal = wrapper.get('[data-testid="apply-modal"]')
        expect(modal.attributes()).toMatchObject({ 'data-open': 'true', 'data-initial': 't-1,t-2', 'data-count': '2', 'data-uuids': 'u-1,u-2', 'data-filter': 'null' })
    })

    it('Bỏ tag is disabled with a hint when nothing selected carries a tag and opens no dialog', async () => {
        const { wrapper } = await renderManage()
        pickedKeys.ref.value = ['u-2']
        await nextTick()
        expect(wrapper.get('[data-testid="tag-detach"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-testid="tag-detach"]').element.closest('[data-tip]')!.getAttribute('data-tip')).toBe('Những người đã chọn chưa có tag nào')
    })

    it('Bỏ tag lists the union of selected-row tags and filter tags, with no popconfirm or modal', async () => {
        const { wrapper } = await renderManage()
        await wrapper.get('[data-testid="filter-tags"]').trigger('click')
        await pickPage(wrapper)
        expect(wrapper.get('[data-testid="tag-detach"]').attributes('disabled')).toBeUndefined()
        expect(wrapper.findAll('[data-testid="detach-option"]').map((o) => o.text())).toEqual(['Triết', 'Sử', 'T1', 'T2', 'T3', 'T4', 'T5'])
        expect(wrapper.find('[data-testid="popconfirm"]').exists()).toBe(false)
        expect(wrapper.get('[data-testid="apply-modal"]').attributes('data-open')).toBe('false')
    })

    it('clicking a tag detaches it once for the selected users, updates chips and toasts with an undo that re-attaches', async () => {
        ;(attachUserTags as any).mockResolvedValue({ data: { affected: 1 } })
        const { wrapper } = await renderManage()
        await pickPage(wrapper)
        const opt = wrapper.findAll('[data-testid="detach-option"]').find((o) => o.text() === 'T2')!
        await opt.trigger('click')
        await flushPromises()
        expect(detachUserTags).toHaveBeenCalledTimes(1)
        expect(detachUserTags).toHaveBeenCalledWith({ tag_uuids: ['t2'], user_uuids: ['u-1', 'u-2'] })
        expect(wrapper.findAll('[data-testid="detach-option"]').map((o) => o.text())).not.toContain('T2')
        const arg = messages.success.mock.calls.at(-1)![0]
        const host = document.createElement('div')
        const { render } = await import('vue')
        render(arg.content, host)
        expect(host.textContent).toContain('Đã gỡ tag T2 khỏi 2 người')
        expect(host.textContent).toContain('Hoàn tác')
        ;(host.querySelector('[data-testid="detach-undo"]') as HTMLElement).click()
        await flushPromises()
        expect(attachUserTags).toHaveBeenCalledWith({ tag_uuids: ['t2'], user_uuids: ['u-1', 'u-2'] })
        expect(wrapper.findAll('[data-testid="detach-option"]').map((o) => o.text())).toContain('T2')
    })

    it('reverts the chips and shows the error when detaching fails', async () => {
        ;(detachUserTags as any).mockRejectedValue({ response: { data: { message: 'boom' } } })
        const { wrapper } = await renderManage()
        await pickPage(wrapper)
        await wrapper.findAll('[data-testid="detach-option"]').find((o) => o.text() === 'T2')!.trigger('click')
        await flushPromises()
        expect(messages.error).toHaveBeenCalledWith('boom')
        expect(wrapper.findAll('[data-testid="detach-option"]').map((o) => o.text())).toContain('T2')
    })

    it('with select-all-by-filter the menu lists only filter tags and detach sends the filter', async () => {
        const { wrapper } = await renderManage()
        await wrapper.get('[data-testid="filter-tags"]').trigger('click')
        await pickPage(wrapper)
        await wrapper.get('[data-testid="select-all-filter"]').trigger('click')
        const opts = wrapper.findAll('[data-testid="detach-option"]')
        expect(opts.map((o) => o.text())).toEqual(['Triết', 'Sử'])
        await opts[0].trigger('click')
        await flushPromises()
        expect(detachUserTags).toHaveBeenCalledWith({ tag_uuids: ['t-1'], filter: { status: 'all' } })
    })

    it('Gắn tag prefill also carries tags every selected row already has', async () => {
        const { wrapper } = await renderManage()
        pickedKeys.ref.value = ['u-1']
        await nextTick()
        await wrapper.get('[data-testid="tag-attach"]').trigger('click')
        expect(wrapper.get('[data-testid="apply-modal"]').attributes('data-initial')).toBe('t1,t2,t3,t4,t5')
        await wrapper.get('[data-testid="filter-tags"]').trigger('click')
        expect(wrapper.get('[data-testid="apply-modal"]').attributes('data-initial')).toBe('t-1,t-2,t1,t2,t3,t4,t5')
        pickedKeys.ref.value = ['u-1', 'u-2']
        await nextTick()
        expect(wrapper.get('[data-testid="apply-modal"]').attributes('data-initial')).toBe('t-1,t-2')
    })

    it('selects all users matching the filter, sends the filter with the total and drops the per-row actions', async () => {
        const { wrapper } = await renderManage()
        expect(wrapper.find('[data-testid="select-all-filter"]').exists()).toBe(false)

        await pickPage(wrapper)
        expect(wrapper.get('[data-testid="select-all-filter"]').text()).toBe('Chọn tất cả 137 người theo bộ lọc')
        await wrapper.get('[data-testid="select-all-filter"]').trigger('click')
        expect(wrapper.get('[data-testid="all-selected"]').exists()).toBe(true)
        expect(wrapper.get('[data-testid="bulk-bar"]').attributes('data-actions')).toBe('')
        expect(wrapper.get('[data-testid="bulk-bar"]').attributes('data-base')).toBe('')

        await wrapper.get('[data-testid="tag-attach"]').trigger('click')
        const modal = wrapper.get('[data-testid="apply-modal"]')
        expect(modal.attributes('data-count')).toBe('137')
        expect(JSON.parse(modal.attributes('data-filter')!)).toEqual({ status: 'all' })

        await wrapper.get('[data-testid="clear-all-filter"]').trigger('click')
        expect(wrapper.find('[data-testid="all-selected"]').exists()).toBe(false)
    })
})
