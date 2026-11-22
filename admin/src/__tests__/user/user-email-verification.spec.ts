import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import { renderWithApp } from '@/test-utils/renderWithApp'
import UserManage from '../../../../cms/Core/admin/pages/user/manage.vue'

const resendUserEmailVerificationMock = vi.hoisted(() => vi.fn())
const userRows = vi.hoisted(() => ({ value: [] as Array<Record<string, unknown>> }))

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
    resendUserEmailVerification: resendUserEmailVerificationMock,
    signInAsUser: vi.fn(),
    updateUser: vi.fn(),
    updateUserPassword: vi.fn(),
}))

vi.mock('@/mixins', () => ({
    iAlert: vi.fn(),
    objectAssignOnlyByKeyHasValue: (_target, source) => source,
    rule: () => true,
}))

vi.mock('@/components/user-tag-apply-modal.vue', () => ({ default: { name: 'UserTagApplyModal', template: '<div />' } }))

vi.mock('@/hooks', () => ({
    useBulkSelection: () => ({ rowSelection: {} }),
    useEnumerates: () => ({ data: ref({ data: [] }) }),
    useUsersByFilter: () => ({
        data: ref({
            data: {
                data: userRows.value,
                currentPage: 1,
                perPage: 20,
                total: userRows.value.length,
            },
        }),
        refetch: vi.fn(),
        isLoading: ref(false),
        isFetching: ref(false),
    }),
}))

vi.mock('ant-design-vue', () => ({
    Form: {
        useForm: () => ({
            clearValidate: vi.fn(),
            resetFields: vi.fn(),
        }),
    },
    message: {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
    },
}))

const globalStubs = {
    breadcrumbs: { template: '<div><slot /></div>' },
    'i-filter': {
        props: ['defaults'],
        template: '<div />',
        methods: {
            getValues() {
                return this.defaults
            },
            applyRoute: () => {},
        },
    },
    'i-bulk-action': { template: '<div />' },
    'i-icon': { template: '<span />' },
    'i-badge': { template: '<span><slot /></span>' },
    'i-switch': { template: '<button type="button" />' },
    'a-table': {
        props: ['dataSource'],
        template: `
            <div>
                <div v-for="(record, index) in dataSource" :key="record.uuid">
                    <slot name="bodyCell" :column="{ key: 'action' }" :record="record" :index="index" />
                </div>
            </div>
        `,
    },
    'a-popconfirm': {
        emits: ['confirm'],
        template: '<span @click="$emit(\'confirm\')"><slot /></span>',
    },
    'a-modal': { template: '<div />' },
    'a-form': { template: '<form><slot /></form>' },
    'a-form-item': { template: '<div><slot /></div>' },
    'a-input-password': { template: '<input />' },
    'router-link': { template: '<a><slot /></a>' },
}

describe('UserManage email verification action', () => {
    beforeEach(() => {
        localStorage.clear()
        localStorage.setItem(
            'CACHE_KEY_ACCOUNT_DATA',
            JSON.stringify({ id: 1, level: 0, name: 'Admin' })
        )
        resendUserEmailVerificationMock.mockReset()
        resendUserEmailVerificationMock.mockResolvedValue({
            status: 'success',
            message: 'Verification email queued.',
        })
        userRows.value = [
            {
                id: 2,
                uuid: 'unverified-user',
                name: 'Unverified User',
                email: 'unverified@example.test',
                emailVerifiedAt: null,
                level: 99,
                roles: [],
                isDeleted: false,
            },
            {
                id: 3,
                uuid: 'verified-user',
                name: 'Verified User',
                email: 'verified@example.test',
                emailVerifiedAt: '2026-08-28 09:00:00',
                level: 99,
                roles: [],
                isDeleted: false,
            },
        ]
    })

    it('shows resend only for unverified users and calls the UUID endpoint action', async () => {
        const { wrapper } = await renderWithApp(UserManage, {
            route: { name: 'user-manage', path: '/user/manage' },
            routes: [
                { path: '/user/create', name: 'user-create', component: { template: '<div />' } },
                { path: '/user/:uuid', name: 'user-detail', component: { template: '<div />' } },
                { path: '/user/:uuid/edit', name: 'user-update', component: { template: '<div />' } },
            ],
            permissions: ['user.viewAny', 'user.update'],
            globalStubs,
        })

        const actions = wrapper.findAll('[data-testid="resend-email-verification"]')
        expect(actions).toHaveLength(1)

        await actions[0].trigger('click')
        await nextTick()

        expect(resendUserEmailVerificationMock).toHaveBeenCalledOnce()
        expect(resendUserEmailVerificationMock).toHaveBeenCalledWith('unverified-user')
    })
})
