import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import SettingBackups from '../../../../cms/Core/admin/pages/setting/backups.vue'
import SettingHub from '@/pages/setting/setting-hub.vue'
import {
    BACKUP_ERROR_CODES,
    backupErrorKey,
    buildDestinationPayload,
    scheduleToTime,
    timeToSchedule,
} from '@/libs/backup'
import { isWebmasterRouteBlocked } from '@/hooks/useCan'

const state = vi.hoisted(() => ({
    webmaster: true,
    accountReady: true,
    query: {} as Record<string, string>,
    alerts: [] as unknown[],
    replace: vi.fn(),
}))
const ajaxMock = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
}))

vi.mock('@/ajax', () => ({ default: ajaxMock }))
vi.mock('@/mixins', () => ({
    rule: () => true,
    iAlert: (value: unknown) => state.alerts.push(value),
}))
vi.mock('@/hooks/useCan', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/hooks/useCan')>()
    return {
        ...actual,
        isWebmaster: () => state.webmaster,
        hasAccountData: () => state.accountReady,
    }
})
vi.mock('@/hooks/useActiveModules', () => ({ isModuleActive: () => true }))
vi.mock('@/config/admin-modules', () => ({ adminModuleSettings: [] }))
vi.mock('vue-router', () => ({
    useRoute: () => ({ query: state.query }),
    useRouter: () => ({ replace: state.replace }),
}))
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}|${JSON.stringify(params)}` : key),
        locale: { value: 'vi' },
    }),
}))

const driverS3 = {
    key: 's3',
    fields: {
        endpoint: { type: 'url', required: true },
        region: { type: 'string', required: false, default: 'auto' },
        bucket: { type: 'string', required: true },
        path_style: { type: 'bool', required: false, default: false },
        prefix: { type: 'string', required: false },
    },
    credential_fields: ['access_key_id', 'secret_access_key'],
}
const driverLocal = { key: 'local', fields: { root: { type: 'path', required: true } }, credential_fields: [] }
const driverGdrive = { key: 'gdrive', fields: { client_id: { type: 'string', required: true } }, credential_fields: ['client_secret'] }

const statusBody = {
    server_nightly: { state: 'ok', label: 'x', date: '2026-11-05', finished_at: '2026-11-05T02:00:00+07:00', objects: [], error: null },
    destinations: [],
    schedules: [],
    drivers: [driverS3, driverLocal, driverGdrive],
}
const destination = (over: Record<string, unknown> = {}) => ({
    uuid: '11111111-1111-4111-8111-111111111111',
    name: 'Nightly R2',
    driver: 's3',
    config: { endpoint: 'https://r2.example.com', region: 'auto', bucket: 'b' },
    credentials_set: { access_key_id: true, secret_access_key: true },
    retention_days: 5,
    lock_days: 0,
    schedule: 'daily@02:30',
    use_for_safety: false,
    enabled: true,
    held_copies: 0,
    last_success_at: null,
    last_failure_at: null,
    last_error_code: null,
    ...over,
})
const emptyHistory = { data: [], meta: { current_page: 1, last_page: 1, total: 0 } }

type Handler = (url: string) => Promise<unknown>
const answer = (overrides: Record<string, Handler> = {}) => {
    ajaxMock.get.mockImplementation((url: string) => {
        if (overrides[url]) return overrides[url](url)
        const bodies: Record<string, unknown> = {
            'backups/status': statusBody,
            'backups/destinations': [destination()],
            backups: emptyHistory,
        }
        return Promise.resolve({ data: { data: bodies[url] } })
    })
}
const pending = () => new Promise(() => {})
const failure = (status: number, code: string) => Promise.reject({ response: { status, data: { error_code: code } } })

// Light stand-ins for Ant Design Vue controls: they render their slots and keep v-model working.
const input = (type: string) =>
    defineComponent({
        props: ['value'],
        emits: ['update:value'],
        setup(props, { emit, attrs }) {
            return () => h('input', { type, value: props.value ?? '', 'data-testid': attrs['data-testid'], onInput: (e: Event) => emit('update:value', (e.target as HTMLInputElement).value) })
        },
    })
const slotOnly = (tag = 'div') => defineComponent({ setup: (_, { slots }) => () => h(tag, slots.default?.()) })
const stubs = {
    'a-input': input('text'),
    'a-input-password': input('password'),
    'a-input-number': input('number'),
    'a-switch': true,
    'a-select': defineComponent({
        props: ['value', 'options', 'disabled'],
        emits: ['update:value'],
        setup: (props, { attrs }) => () => h('div', { 'data-testid': attrs['data-testid'], 'data-value': props.value ?? '' }),
    }),
    'a-time-picker': true,
    'a-tag': slotOnly('span'),
    'a-skeleton': true,
    'a-alert': defineComponent({ props: ['message', 'description'], setup: (props) => () => h('div', [props.message, ' ', props.description]) }),
    'a-drawer': defineComponent({ props: ['open'], setup: (props, { slots }) => () => (props.open ? h('div', slots.default?.()) : null) }),
    'a-popconfirm': defineComponent({
        props: ['title', 'description'],
        emits: ['confirm'],
        setup: (props, { slots, emit }) => () =>
            h('span', [slots.default?.(), h('i', { 'data-testid': 'popconfirm-text' }, `${props.title} ${props.description ?? ''}`), h('button', { 'data-testid': 'popconfirm-yes', onClick: () => emit('confirm') })]),
    }),
    'i-icon': true,
    'i-stat-tile': defineComponent({ props: ['label', 'value'], setup: (p) => () => h('div', `${p.label}:${p.value}`) }),
    'i-button': defineComponent({
        props: ['disabled', 'loading', 'nativeType'],
        emits: ['click'],
        setup: (props, { slots, emit, attrs }) => () =>
            h('button', { type: props.nativeType || 'button', disabled: props.disabled || props.loading, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, [slots.icon?.(), slots.default?.()]),
    }),
}

const mountPage = async () => {
    const wrapper = mount(SettingBackups, { global: { mocks: { $t: (k: string, p?: Record<string, unknown>) => (p ? `${k}|${JSON.stringify(p)}` : k) }, stubs } })
    await flushPromises()
    return wrapper
}

beforeEach(() => {
    ajaxMock.get.mockReset()
    ajaxMock.post.mockReset()
    ajaxMock.put.mockReset()
    ajaxMock.delete.mockReset()
    state.webmaster = true
    state.accountReady = true
    state.query = {}
    state.alerts = []
    state.replace.mockReset()
    answer()
})

describe('Backups page states', () => {
    it('shows a loading state for each block while requests are pending', async () => {
        ajaxMock.get.mockImplementation(pending)
        const wrapper = await mountPage()
        for (const block of ['status', 'destinations', 'history']) {
            expect(wrapper.find(`[data-testid="backup-${block}-loading"]`).exists()).toBe(true)
        }
    })

    it('shows empty states for destinations and history', async () => {
        answer({ 'backups/destinations': () => Promise.resolve({ data: { data: [] } }) })
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="backup-destinations-empty"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="backup-history-empty"]').exists()).toBe(true)
    })

    it.each([
        ['status', 'backups/status'],
        ['destinations', 'backups/destinations'],
        ['history', 'backups'],
    ])('shows an error with retry for %s and recovers on retry', async (block, url) => {
        let fail = true
        const normal = ajaxMock.get.getMockImplementation() as Handler
        answer({
            [url]: (u: string) => (fail ? failure(500, 'BACKUP_UNEXPECTED') : normal(u)),
        })
        const wrapper = await mountPage()
        const error = wrapper.find(`[data-testid="backup-${block}-error"]`)
        expect(error.exists()).toBe(true)
        expect(error.text()).toContain('text.backup.error.unexpected')

        fail = false
        await wrapper.find(`[data-testid="backup-${block}-retry"]`).trigger('click')
        await flushPromises()
        expect(wrapper.find(`[data-testid="backup-${block}-error"]`).exists()).toBe(false)
    })

    it('shows the translated last error of a destination, including provider HTTP families', async () => {
        answer({
            'backups/destinations': () =>
                Promise.resolve({ data: { data: [destination({ last_failure_at: '2026-11-05T01:00:00Z', last_error_code: 'BACKUP_GDRIVE_HTTP_403' })] } }),
        })
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="backup-destination-result"]').text()).toContain('text.backup.error.gdrive_http')
    })

    it('reads the OAuth return query', async () => {
        state.query = { oauth: 'failed' }
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="backup-oauth-notice"]').text()).toContain('text.backup.oauth.failed')
    })
})

describe('Backups destination drawer', () => {
    it('never prefills secrets and does not send a blank secret on update', async () => {
        ajaxMock.put.mockResolvedValue({ data: { data: destination() } })
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-edit"]').trigger('click')

        const secrets = wrapper.findAll('input[type="password"]')
        expect(secrets).toHaveLength(2)
        secrets.forEach((el) => expect((el.element as HTMLInputElement).value).toBe(''))
        expect(wrapper.findAll('[data-testid="backup-secret-saved"]')).toHaveLength(2)

        await wrapper.find('form[data-testid="backup-form"]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.put).toHaveBeenCalledTimes(1)
        const [url, payload] = ajaxMock.put.mock.calls[0]
        expect(url).toBe('backups/destinations/11111111-1111-4111-8111-111111111111')
        expect(payload.credentials).toEqual({})
        expect(payload).not.toHaveProperty('driver')
    })

    it('sends a secret only after it is typed', async () => {
        ajaxMock.put.mockResolvedValue({ data: { data: destination() } })
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-edit"]').trigger('click')
        await wrapper.find('input[type="password"]').setValue('new-secret')
        await wrapper.find('form[data-testid="backup-form"]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.put.mock.calls[0][1].credentials).toEqual({ access_key_id: 'new-secret' })
    })
})

const pickDriver = async (wrapper: ReturnType<typeof mount>, key: string) => {
    wrapper.findComponent('[data-testid="backup-field-driver"]').vm.$emit('update:value', key)
    await flushPromises()
}

describe('Backups add destination form', () => {
    it('starts on the server folder driver and hides the S3 fields', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-add"]').trigger('click')
        expect(wrapper.find('[data-testid="backup-field-driver"]').attributes('data-value')).toBe('local')
        expect(wrapper.find('[data-testid="backup-field-root"]').exists()).toBe(true)
        for (const key of ['endpoint', 'bucket', 'region', 'prefix']) {
            expect(wrapper.find(`[data-testid="backup-field-${key}"]`).exists()).toBe(false)
        }
        expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    })

    it('keeps region, path-style and folder under a collapsed Advanced section for S3', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-add"]').trigger('click')
        await pickDriver(wrapper, 's3')

        for (const key of ['endpoint', 'bucket']) expect(wrapper.find(`[data-testid="backup-field-${key}"]`).exists()).toBe(true)
        expect(wrapper.findAll('input[type="password"]')).toHaveLength(2)
        expect(wrapper.findAll('[data-testid="backup-required"]').length).toBeGreaterThanOrEqual(5)
        expect(wrapper.find('[data-testid="backup-field-region"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="backup-field-prefix"]').exists()).toBe(false)

        const toggle = wrapper.find('[data-testid="backup-advanced"]')
        expect(toggle.attributes('aria-expanded')).toBe('false')
        await toggle.trigger('click')
        expect(toggle.attributes('aria-expanded')).toBe('true')
        expect(wrapper.find('[data-testid="backup-field-region"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="backup-field-prefix"]').exists()).toBe(true)
    })

    it('applies a provider preset without adding anything to the payload', async () => {
        ajaxMock.post.mockResolvedValue({ data: { data: destination() } })
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-add"]').trigger('click')
        await pickDriver(wrapper, 's3')
        wrapper.findComponent('[data-testid="backup-field-provider"]').vm.$emit('update:value', 'r2')
        await flushPromises()
        expect(wrapper.find('[data-testid="backup-provider-help"]').text()).toContain('text.backup.provider.r2_help')

        await wrapper.find('[data-testid="backup-field-name"]').setValue('R2')
        await wrapper.find('[data-testid="backup-field-endpoint"]').setValue('https://acc.r2.cloudflarestorage.com')
        await wrapper.find('[data-testid="backup-field-bucket"]').setValue('b')
        await wrapper.find('[data-testid="backup-secret-access_key_id"]').setValue('k')
        await wrapper.find('[data-testid="backup-secret-secret_access_key"]').setValue('s')
        await wrapper.find('form[data-testid="backup-form"]').trigger('submit')
        await flushPromises()
        const payload = ajaxMock.post.mock.calls[0][1]
        expect(Object.keys(payload).sort()).toEqual(
            ['config', 'credentials', 'driver', 'enabled', 'lock_days', 'name', 'retention_days', 'schedule', 'use_for_safety'].sort()
        )
        expect(payload.config).toMatchObject({ region: 'auto', path_style: true, bucket: 'b' })
    })

    it('shows an error on screen for an empty save and sends no request', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-add"]').trigger('click')
        await wrapper.find('form[data-testid="backup-form"]').trigger('submit')
        await flushPromises()
        expect(ajaxMock.post).not.toHaveBeenCalled()
        expect(wrapper.find('[data-testid="backup-form-error"]').text()).toContain('text.backup.form.fix_errors')
        expect(wrapper.find('[data-testid="backup-error-name"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="backup-error-root"]').exists()).toBe(true)
    })

    it('opens Advanced when a server error targets a field inside it, and maps codes to fields', async () => {
        ajaxMock.post.mockImplementation(() => Promise.reject({ response: { status: 422, data: { error_code: 'BACKUP_BUCKET_REQUIRED', data: { errors: ['BACKUP_BUCKET_REQUIRED'] } } } }))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-add"]').trigger('click')
        await pickDriver(wrapper, 's3')
        await wrapper.find('[data-testid="backup-field-name"]').setValue('R2')
        await wrapper.find('[data-testid="backup-field-endpoint"]').setValue('https://x.example.com')
        await wrapper.find('[data-testid="backup-field-bucket"]').setValue('b')
        await wrapper.find('[data-testid="backup-secret-access_key_id"]').setValue('k')
        await wrapper.find('[data-testid="backup-secret-secret_access_key"]').setValue('s')
        await wrapper.find('form[data-testid="backup-form"]').trigger('submit')
        await flushPromises()
        expect(wrapper.find('[data-testid="backup-form-error"]').text()).toContain('text.backup.error.bucket_required')
        expect(wrapper.find('[data-testid="backup-error-bucket"]').exists()).toBe(true)
    })

    it('shows the server validation message (406) in the sticky error area', async () => {
        ajaxMock.post.mockImplementation(() =>
            Promise.reject({ response: { status: 406, data: { status: 'error', message: ['The name has already been taken.'], error_code: 'VALIDATION_FAILED' } } })
        )
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-add"]').trigger('click')
        await wrapper.find('[data-testid="backup-field-name"]').setValue('Dup')
        await wrapper.find('[data-testid="backup-field-root"]').setValue('/var/backups/ispa')
        await wrapper.find('form[data-testid="backup-form"]').trigger('submit')
        await flushPromises()
        const error = wrapper.find('[data-testid="backup-form-error"]').text()
        expect(error).toContain('text.backup.error.validation_failed')
        expect(error).toContain('The name has already been taken.')
        expect((wrapper.find('[data-testid="backup-save"]').element as HTMLButtonElement).disabled).toBe(false)
    })

    it('keeps the stored type when editing', async () => {
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-edit"]').trigger('click')
        expect(wrapper.find('[data-testid="backup-field-driver"]').attributes('data-value')).toBe('s3')
        expect(wrapper.find('[data-testid="backup-field-provider"]').exists()).toBe(false)
    })
})

describe('Backups run now', () => {
    it('sends exactly one request on a double click', async () => {
        ajaxMock.post.mockImplementation(() => new Promise(() => {}))
        const wrapper = await mountPage()
        const button = wrapper.find('[data-testid="backup-run"]')
        await button.trigger('click')
        await button.trigger('click')
        await flushPromises()
        expect(ajaxMock.post.mock.calls.filter(([url]) => url === 'backups/run')).toHaveLength(1)
        expect((button.element as HTMLButtonElement).disabled).toBe(true)
    })

    it('enables the button again after an error and shows the translated error', async () => {
        ajaxMock.post.mockImplementation(() => failure(409, 'BACKUP_ALREADY_RUNNING'))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="backup-run"]').trigger('click')
        await flushPromises()
        expect((wrapper.find('[data-testid="backup-run"]').element as HTMLButtonElement).disabled).toBe(false)
        expect(JSON.stringify(state.alerts)).toContain('text.backup.error.already_running')
    })
})

describe('Backups delete', () => {
    it('tells in the confirm that stored backup files are kept', async () => {
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="popconfirm-text"]').text()).toContain('text.backup.delete_confirm')
    })

    it('shows empty status values as muted text instead of a result tile', async () => {
        const wrapper = await mountPage()
        const muted = wrapper.findAll('[data-testid="backup-status-muted"]')
        expect(muted.length).toBeGreaterThan(0)
        expect(muted[0].classes()).toContain('text-sm')
    })

    it('maps a 409 in-use answer to the in-use message', async () => {
        ajaxMock.delete.mockImplementation(() => failure(409, 'BACKUP_DESTINATION_IN_USE'))
        const wrapper = await mountPage()
        await wrapper.find('[data-testid="popconfirm-yes"]').trigger('click')
        await flushPromises()
        expect(JSON.stringify(state.alerts)).toContain('text.backup.delete_in_use')
    })
})

describe('Backups access', () => {
    it('renders nothing and makes no request for a known non-Webmaster, and leaves the page', async () => {
        state.webmaster = false
        const wrapper = await mountPage()
        expect(wrapper.find('[data-testid="backups-page"]').exists()).toBe(false)
        expect(ajaxMock.get).not.toHaveBeenCalled()
        expect(state.replace).toHaveBeenCalledWith({ name: 'dashboard' })
    })

    it('blocks a webmasterOnly route only for a known non-Webmaster account', () => {
        expect(isWebmasterRouteBlocked({ webmasterOnly: true }, true, false)).toBe(true)
        expect(isWebmasterRouteBlocked({ webmasterOnly: true }, true, true)).toBe(false)
        expect(isWebmasterRouteBlocked({ webmasterOnly: true }, false, false)).toBe(false)
        expect(isWebmasterRouteBlocked({}, true, false)).toBe(false)
    })

    const mountHub = () =>
        mount(SettingHub, {
            global: {
                mocks: { $t: (key: string) => key },
                stubs: { 'router-link': { props: ['to'], template: '<a><slot /></a>' }, 'i-icon': true },
            },
        })

    it('counts the Webmaster-only hub entries (backups, configuration packages) only for a Webmaster', () => {
        state.webmaster = true
        expect(mountHub().find('[data-testid="setting-hub-system"]').text()).toContain('8 setting.hub.items')
        state.webmaster = false
        expect(mountHub().find('[data-testid="setting-hub-system"]').text()).toContain('6 setting.hub.items')
    })
})

describe('Backups helpers', () => {
    it('maps error codes to text.backup.error keys', () => {
        expect(backupErrorKey('BACKUP_ALREADY_RUNNING')).toBe('text.backup.error.already_running')
        expect(backupErrorKey('WEBMASTER_ONLY')).toBe('text.backup.error.webmaster_only')
        expect(backupErrorKey('BACKUP_GDRIVE_HTTP_404')).toBe('text.backup.error.gdrive_http')
        expect(backupErrorKey('BACKUP_ONEDRIVE_HTTP_503')).toBe('text.backup.error.onedrive_http')
        expect(backupErrorKey('BACKUP_SOMETHING_NEW')).toBe('text.backup.error.unknown')
        expect(backupErrorKey('')).toBeNull()
        expect(backupErrorKey(null)).toBeNull()
        expect(new Set(BACKUP_ERROR_CODES.map(backupErrorKey)).size).toBe(BACKUP_ERROR_CODES.length)
    })

    it('converts schedules both ways', () => {
        expect(scheduleToTime('daily@02:30')).toBe('02:30')
        expect(scheduleToTime(null)).toBeNull()
        expect(timeToSchedule('02:30')).toBe('daily@02:30')
        expect(timeToSchedule(null)).toBeNull()
    })

    it('builds a create payload with the driver and only typed secrets', () => {
        const payload = buildDestinationPayload(
            { name: ' R2 ', driver: 's3', config: { endpoint: ' https://x ', region: 'auto', bucket: 'b' }, credentials: { access_key_id: 'k', secret_access_key: '  ' }, retention_days: 4, lock_days: 0, time: '03:00', use_for_safety: true, enabled: true },
            driverS3
        )
        expect(payload).toMatchObject({ name: 'R2', driver: 's3', schedule: 'daily@03:00', use_for_safety: true })
        expect(payload.config.endpoint).toBe('https://x')
        expect(payload.credentials).toEqual({ access_key_id: 'k' })
    })
})
