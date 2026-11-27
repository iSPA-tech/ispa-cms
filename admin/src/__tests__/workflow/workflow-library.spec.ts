import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ fetchLibrary: vi.fn(), fetchTemplate: vi.fn(), installTemplate: vi.fn() }))
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const access = vi.hoisted(() => ({ rules: new Set<string>() }))
const router = vi.hoisted(() => ({ push: vi.fn() }))

vi.mock('@core-admin/workflow-library-api', async (original) => ({ ...(await original<Record<string, unknown>>()), ...api }))
vi.mock('@/ajax', () => ({ default: http }))
vi.mock('@/mixins', () => ({ rule: (key: string) => access.rules.has(key) }))
vi.mock('vue-router', () => ({ useRouter: () => router }))
vi.mock('vue-i18n', () => ({
    // Known module and group names are translated; every other key answers with itself (plus its parameters).
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) =>
            ({ 'nav.module.sample-module.name': 'Mô-đun mẫu', 'nav.module.helpdesk.name': 'Trung tâm trợ giúp', 'text.workflow.library.group.posts': 'Bài viết' })[key] ||
            (params ? `${key}${JSON.stringify(params)}` : key),
    }),
}))

import LibraryPage from '@core-admin/pages/setting/workflow-library.vue'
import WorkflowsToolbar from '@core-admin/pages/setting/workflows-manage/components/workflows-toolbar.vue'

const real = await vi.importActual<typeof import('@core-admin/workflow-library-api')>('@core-admin/workflow-library-api')
const { copyNote, filterTemplates, fold, groupsOf, humanize, libraryError, mainAction, moduleKey, reportRows, shapeTemplate, stepNamesOf, tagColor, versionParts } = real

const stubs = {
    'no-permission-error': { template: '<div data-testid="no-permission" />' },
    'router-link': { props: ['to'], template: '<a :data-to="to.name"><slot /></a>' },
    'a-button': { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
    'a-alert': { props: ['message', 'description'], template: '<div role="alert"><b>{{ message }}</b><i>{{ description }}</i><slot name="description" /><slot name="action" /></div>' },
    'a-spin': true,
    'a-tag': { template: '<span><slot /></span>' },
    'a-input': { props: ['value'], emits: ['update:value'], template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
    'a-checkbox': { props: ['checked'], emits: ['update:checked'], template: '<label><input type="checkbox" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" /><slot /></label>' },
    'a-drawer': {
        props: ['open', 'title'],
        emits: ['close', 'afterOpenChange'],
        template: '<aside v-if="open" role="dialog"><h2 data-testid="drawer-title">{{ title }}</h2><button data-testid="drawer-close" @click="$emit(\'close\'); $emit(\'afterOpenChange\', false)">x</button><slot /></aside>',
    },
}

const row = (over: Record<string, unknown> = {}) => ({
    key: 'ispa.church-story',
    publisher: 'iSPA',
    name: 'Bài về nhà thờ',
    description: 'AI viết bài, người duyệt trước khi đăng.',
    group: 'posts',
    latest_version: 20261201,
    latest_version_label: '2026.12.01',
    requires: { modules: ['Core', 'SampleModule'], node_types: [] },
    summary: { steps: [{ step_code: 'write', name: 'AI viết bài' }], credential_kinds: [] },
    summary_lines: {
        does: ['Chọn nhà thờ', 'AI viết bài'],
        asks: ['Ai duyệt ở bước “Duyệt bài”'],
        may: ['Dùng AI (tính vào ngân sách AI của website)'],
        tags: [
            { key: 'ai_spend', label: 'Tốn phí AI' },
            { key: 'needs_approval', label: 'Chờ người duyệt' },
        ],
    },
    notes: 'Thêm bước gửi Telegram.',
    available: true,
    missing_modules: [],
    installed: null,
    update_available: false,
    ...over,
})
const installedRow = (over: Record<string, unknown> = {}) => ({ project_id: 7, slug: 'church-story', name: 'Bài về nhà thờ', version: 20261101, version_label: '2026.11.01', modified: false, is_active: false, setup_url: '/setting/automation/workflows/setup/7', copies: 1, ...over })
const detailOf = (over: Record<string, unknown> = {}) => shapeTemplate({ ...row(), signature: { verified: true, key_id: 'k1', publisher: 'iSPA' }, install_as_copy: false, copy_reason: null, ...over })
const fresh = shapeTemplate(row())
const updated = shapeTemplate(row({ key: 'ispa.gospel', name: 'Phúc Âm mỗi ngày', group: 'social-posts', installed: installedRow({ modified: true }), update_available: true }))
const current = shapeTemplate(row({ key: 'ispa.seo', name: 'Viết lại mô tả', group: 'posts', installed: installedRow({ project_id: 9, slug: 'seo', version: 20261201, version_label: '2026.12.01', is_active: true }) }))
const blocked = shapeTemplate(row({ key: 'ispa.helpdesk', name: 'Phân loại hỗ trợ', group: 'support', requires: { modules: ['Helpdesk'] }, available: false, missing_modules: ['Helpdesk'] }))
const refusal = (status: number, error_code?: string, data?: unknown) => ({ response: { status, data: { message: 'server text', error_code, data } } })

const open = async () => {
    const wrapper = mount(LibraryPage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    return wrapper
}
const cards = (wrapper: any) => wrapper.findAll('[data-testid="library-card"]').map((card: any) => card.attributes('data-key'))
const pick = async (wrapper: any, key: string) => {
    await wrapper.find(`[data-testid="library-card"][data-key="${key}"]`).trigger('click')
    await flushPromises()
}

beforeEach(() => {
    Object.values(api).forEach((mock) => mock.mockReset())
    http.get.mockReset()
    http.post.mockReset()
    router.push.mockReset().mockResolvedValue(undefined)
    access.rules = new Set(['workflow_project.viewAny', 'workflow_project.create'])
    api.fetchLibrary.mockResolvedValue([fresh, updated, current, blocked])
    api.fetchTemplate.mockImplementation(async (key: string) => detailOf(row({ key })))
    document.body.innerHTML = ''
})

describe('library rules', () => {
    it('shapes a row: Core is never listed as a need, tags and lines are kept, an update needs an install', () => {
        expect(fresh.modules).toEqual(['SampleModule'])
        expect(fresh.tags.map((tag) => tag.key)).toEqual(['ai_spend', 'needs_approval'])
        expect(fresh.does).toEqual(['Chọn nhà thờ', 'AI viết bài'])
        expect(shapeTemplate(row({ update_available: true })).update_available).toBe(false)
        expect(shapeTemplate(row({ summary: { credential_kinds: ['facebook_page'] } })).needs_account).toBe(true)
        expect(shapeTemplate(null)).toMatchObject({ key: '', tags: [], installed: null, signature_verified: false })
    })

    it('filters by availability, group and a search without accents over name, description and tags', () => {
        const all = [fresh, updated, current, blocked]
        expect(filterTemplates(all).map((item) => item.key)).toEqual(['ispa.church-story', 'ispa.gospel', 'ispa.seo'])
        expect(filterTemplates(all, { onlyAvailable: false })).toHaveLength(4)
        expect(filterTemplates(all, { group: 'support', onlyAvailable: false }).map((item) => item.key)).toEqual(['ispa.helpdesk'])
        expect(filterTemplates(all, { group: 'support' })).toEqual([])
        expect(filterTemplates(all, { search: 'nha tho' }).map((item) => item.key)).toEqual(['ispa.church-story'])
        expect(filterTemplates(all, { search: 'CHO NGUOI DUYET' })).toHaveLength(3)
        expect(fold('Đăng bài')).toBe('dang bai')
    })

    it('builds the group chips from the groups present and names things without a translation', () => {
        expect(groupsOf([fresh, updated, current, blocked, shapeTemplate(row({ group: null }))])).toEqual(['posts', 'social-posts', 'support'])
        expect(humanize('social-posts')).toBe('Social posts')
        expect(humanize('SampleModule')).toBe('Sample Module')
        expect(moduleKey('SampleModule')).toBe('sample-module')
        expect(tagColor('publish')).toBe('red')
        expect(tagColor('something_new')).toBe('default')
    })

    it('chooses the main button by installed, update and availability', () => {
        expect(mainAction(fresh)).toBe('install')
        expect(mainAction(updated)).toBe('install_copy')
        // A newer version of an install not changed on this site updates it in place.
        expect(mainAction(shapeTemplate(row({ installed: installedRow(), update_available: true })))).toBe('update')
        expect(mainAction(detailOf({ installed: installedRow(), update_available: true, update_in_place: true }))).toBe('update')
        expect(mainAction(detailOf({ installed: installedRow(), update_available: true, update_in_place: false }))).toBe('install_copy')
        expect(mainAction(current)).toBe('open')
        expect(mainAction(blocked)).toBe('unavailable')
        expect(mainAction(null)).toBeNull()
    })

    it('says why a new version installs as a copy', () => {
        expect(copyNote(detailOf({ installed: installedRow(), update_available: true, update_in_place: false, copy_reason: 'modified' }))).toBe('modified')
        expect(copyNote(detailOf({ installed: installedRow(), update_available: true, update_in_place: true }))).toBeNull()
        expect(copyNote(updated)).toBe('modified')
        expect(copyNote(fresh)).toBeNull()
        expect(copyNote(current)).toBeNull()
    })

    it('lists only the version parts that apply', () => {
        expect(versionParts(fresh)).toEqual([{ key: 'from', value: 'iSPA' }, { key: 'version', value: '2026.12.01' }])
        expect(versionParts(updated).map((part) => part.key)).toEqual(['from', 'version_new', 'version_in_use'])
        expect(versionParts(current)).toEqual([{ key: 'from', value: 'iSPA' }, { key: 'version_in_use', value: '2026.12.01' }])
    })

    it('maps a failed request to what the page can say and whether trying again helps', () => {
        expect(libraryError(refusal(503, 'WORKFLOW_HUB_UNREACHABLE'))).toMatchObject({ kind: 'unreachable', retry: true })
        expect(libraryError(refusal(404, 'WORKFLOW_HUB_NOT_FOUND'))).toMatchObject({ kind: 'not_found', retry: false })
        // Upgrade all (spec 2026-11-26) is running: wait for it, pressing again does not help.
        expect(libraryError(refusal(409, 'UPDATE_BATCH_RUNNING'))).toMatchObject({ kind: 'batch_running', retry: false })
        expect(libraryError(refusal(422, 'WORKFLOW_BUNDLE_UNTRUSTED'))).toMatchObject({ kind: 'untrusted', retry: false })
        expect(libraryError(refusal(409, 'WORKFLOW_TEMPLATE_NOT_AVAILABLE', { missing_modules: ['Helpdesk'] }))).toMatchObject({ kind: 'not_available', retry: false, missingModules: ['Helpdesk'] })
        const report = { problems: [{ code: 'unknown_node_type', step_code: 'write' }], binding_problems: [{ code: 'binding_missing' }], slug_problems: [] }
        expect(libraryError(refusal(422, 'WORKFLOW_TEMPLATE_NOT_INSTALLABLE', report))).toMatchObject({ kind: 'not_installable', report })
        expect(reportRows(report).map((item) => item.code)).toEqual(['unknown_node_type', 'binding_missing'])
        expect(libraryError(refusal(403)).kind).toBe('forbidden')
        expect(libraryError(refusal(429))).toMatchObject({ kind: 'busy', retry: true })
        expect(libraryError(new Error('network'))).toMatchObject({ kind: 'failed', retry: true, report: null })
        expect(stepNamesOf(fresh)).toEqual({ write: 'AI viết bài' })
    })

    it('asks the server quietly, with refresh only when wanted, and installs as a copy only when asked', async () => {
        http.get.mockResolvedValue({ data: { data: [row(), { name: 'no key' }] } })
        expect((await real.fetchLibrary()).map((item) => item.key)).toEqual(['ispa.church-story'])
        expect(http.get).toHaveBeenLastCalledWith('workflows/library', { silent: true, params: {} })
        await real.fetchLibrary(true)
        expect(http.get).toHaveBeenLastCalledWith('workflows/library', { silent: true, params: { refresh: 1 } })

        http.post.mockResolvedValue({ data: { data: { project_id: 12 } } })
        expect(await real.installTemplate('ispa.church-story')).toEqual({ project_id: 12 })
        expect(http.post).toHaveBeenLastCalledWith('workflows/library/ispa.church-story/install', {}, { silent: true })
        await real.installTemplate('ispa.church-story', { asCopy: true })
        expect(http.post).toHaveBeenLastCalledWith('workflows/library/ispa.church-story/install', { as_copy: true }, { silent: true })
    })
})

describe('library page', () => {
    it('is closed to an account that may not see workflows', async () => {
        access.rules = new Set()
        const wrapper = await open()
        expect(wrapper.find('[data-testid="workflow-library-not-allowed"]').exists()).toBe(true)
        expect(api.fetchLibrary).not.toHaveBeenCalled()
    })

    it('lists the templates that work here, with their badges, and shows the rest on request', async () => {
        const wrapper = await open()
        expect(cards(wrapper)).toEqual(['ispa.church-story', 'ispa.gospel', 'ispa.seo'])
        const first = wrapper.find('[data-key="ispa.church-story"]')
        expect(first.find('[data-testid="library-card-installed"]').exists()).toBe(false)
        expect(first.findAll('[data-testid="library-card-tag"]').map((tag) => tag.text())).toEqual(['Tốn phí AI', 'Chờ người duyệt'])
        expect(first.find('[data-testid="library-card-needs"]').text()).toContain('Mô-đun mẫu')
        const second = wrapper.find('[data-key="ispa.gospel"]')
        expect(second.find('[data-testid="library-card-installed"]').exists()).toBe(true)
        expect(second.find('[data-testid="library-card-update"]').exists()).toBe(true)

        await wrapper.find('[data-testid="library-only-available"] input').setValue(false)
        expect(cards(wrapper)).toContain('ispa.helpdesk')
        const off = wrapper.find('[data-key="ispa.helpdesk"]')
        expect(off.attributes('data-available')).toBe('no')
        expect(off.find('[data-testid="library-card-missing"]').text()).toContain('Trung tâm trợ giúp')
        expect(off.find('[data-testid="library-card-tag"]').exists()).toBe(false)
    })

    it('filters by group chip and search, and offers a way out when nothing matches', async () => {
        const wrapper = await open()
        const chips = wrapper.findAll('[data-testid="library-group"]')
        expect(chips.map((chip) => chip.text())).toEqual(['text.workflow.library.group_all', 'Bài viết', 'Social posts', 'Support'])
        expect(chips[0].attributes('aria-pressed')).toBe('true')

        await chips[1].trigger('click')
        expect(cards(wrapper)).toEqual(['ispa.church-story', 'ispa.seo'])
        await wrapper.find('[data-testid="library-search"]').setValue('mo ta')
        expect(cards(wrapper)).toEqual(['ispa.seo'])

        await chips[3].trigger('click')
        await wrapper.find('[data-testid="library-search"]').setValue('')
        expect(cards(wrapper)).toEqual([])
        expect(wrapper.find('[data-testid="library-no-match"]').text()).toContain('hidden_unavailable{"count":1}')
        await wrapper.find('[data-testid="library-show-all"]').trigger('click')
        expect(cards(wrapper)).toHaveLength(4)
    })

    it('says so when the library is empty', async () => {
        api.fetchLibrary.mockResolvedValue([])
        const wrapper = await open()
        expect(wrapper.find('[data-testid="library-empty"]').text()).toContain('text.workflow.library.empty_title')
        expect(wrapper.find('[data-testid="library-search"]').exists()).toBe(false)
    })

    it('explains an unreachable library in its own words and loads again past the cache', async () => {
        api.fetchLibrary.mockRejectedValueOnce(refusal(503, 'WORKFLOW_HUB_UNREACHABLE'))
        const wrapper = await open()
        const alert = wrapper.find('[data-testid="library-load-error"]')
        expect(alert.attributes('data-kind')).toBe('unreachable')
        expect(alert.text()).toContain('text.workflow.library.error.unreachable')
        expect(alert.text()).toContain('text.workflow.library.error_hint.unreachable')
        expect(alert.text()).not.toContain('server text')

        await wrapper.find('[data-testid="library-retry"]').trigger('click')
        await flushPromises()
        expect(api.fetchLibrary).toHaveBeenLastCalledWith(true)
        expect(cards(wrapper)).toHaveLength(3)
    })

    it('offers "bring in from a file" only to accounts that may create workflows', async () => {
        const wrapper = await open()
        await wrapper.find('[data-testid="library-import-file"]').trigger('click')
        expect(router.push).toHaveBeenCalledWith({ name: 'workflows-import' })

        access.rules = new Set(['workflow_project.viewAny'])
        const reader = await open()
        expect(reader.find('[data-testid="library-import-file"]').exists()).toBe(false)
        await pick(reader, 'ispa.church-story')
        expect(reader.find('[data-testid="library-install"]').exists()).toBe(false)
        expect(reader.find('[data-testid="library-install-not-allowed"]').exists()).toBe(true)
    })

    it('shows what a template does, asks and may do, then installs it once and opens its setup', async () => {
        const wrapper = await open()
        await pick(wrapper, 'ispa.church-story')
        expect(api.fetchTemplate).toHaveBeenCalledWith('ispa.church-story')
        expect(wrapper.find('[data-testid="drawer-title"]').text()).toBe('Bài về nhà thờ')
        expect(wrapper.find('[data-testid="library-detail-versions"]').text()).toBe('text.workflow.library.from{"value":"iSPA"} · text.workflow.library.version{"value":"2026.12.01"}')
        expect(wrapper.findAll('[data-testid="library-detail-does"] li')).toHaveLength(2)
        expect(wrapper.find('[data-testid="library-detail-asks"]').text()).toContain('Ai duyệt')
        expect(wrapper.find('[data-testid="library-detail-may"]').text()).toContain('Dùng AI')
        expect(wrapper.find('[data-testid="library-detail-signed"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="library-detail-update"]').exists()).toBe(false)

        let finish: (value: unknown) => void = () => {}
        api.installTemplate.mockReturnValue(new Promise((resolve) => (finish = resolve)))
        const button = wrapper.find('[data-testid="library-install"]')
        expect(button.attributes('data-action')).toBe('install')
        expect(button.text()).toBe('text.workflow.library.install')
        await button.trigger('click')
        await button.trigger('click')
        expect(api.installTemplate).toHaveBeenCalledTimes(1)
        expect(api.installTemplate).toHaveBeenCalledWith('ispa.church-story', { asCopy: false })
        expect(button.attributes('disabled')).toBeDefined()

        finish({ project_id: 12, slug: 'church-story', setup_url: '/setting/automation/workflows/setup/12' })
        await flushPromises()
        expect(router.push).toHaveBeenCalledWith({ name: 'workflows-setup', params: { project: 'church-story' } })
        // Still busy: the page is leaving.
        expect(wrapper.find('[data-testid="library-install"]').attributes('disabled')).toBeDefined()
    })

    it('installs a new version as a copy and says why the installed one is kept', async () => {
        api.fetchTemplate.mockResolvedValue(detailOf({ key: 'ispa.gospel', installed: installedRow({ modified: true }), update_available: true, install_as_copy: true, copy_reason: 'modified' }))
        api.installTemplate.mockResolvedValue({ project_id: 13 })
        const wrapper = await open()
        await pick(wrapper, 'ispa.gospel')
        expect(wrapper.find('[data-testid="library-detail-versions"]').text()).toContain('version_new{"value":"2026.12.01"} · text.workflow.library.version_in_use{"value":"2026.11.01"}')
        expect(wrapper.find('[data-testid="library-detail-update"]').text()).toContain('update_title{"version":"2026.12.01"}')
        expect(wrapper.find('[data-testid="library-detail-notes"]').text()).toBe('Thêm bước gửi Telegram.')
        expect(wrapper.find('[data-testid="library-detail-copy-reason"]').attributes('data-reason')).toBe('modified')

        const button = wrapper.find('[data-testid="library-install"]')
        expect(button.text()).toBe('text.workflow.library.install_copy')
        await button.trigger('click')
        await flushPromises()
        expect(api.installTemplate).toHaveBeenCalledWith('ispa.gospel', { asCopy: true })
        expect(router.push).toHaveBeenCalledWith({ name: 'workflows-setup', params: { project: 13 } })
    })

    it('updates an install not changed on this site in place and says the running version stays', async () => {
        api.fetchTemplate.mockResolvedValue(detailOf({ key: 'ispa.gospel', installed: installedRow(), update_available: true, update_in_place: true }))
        api.installTemplate.mockResolvedValue({ project_id: 7, updated_in_place: true, as_copy: false })
        const wrapper = await open()
        await pick(wrapper, 'ispa.gospel')
        expect(wrapper.find('[data-testid="library-detail-copy-reason"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="library-detail-update-in-place"]').text()).toBe('text.workflow.library.update_in_place_note')
        expect(wrapper.find('[data-testid="library-install-footnote"]').text()).toBe('text.workflow.library.update_footnote')
        await wrapper.find('[data-testid="library-open-current"]').trigger('click')
        expect(router.push).toHaveBeenCalledWith({ name: 'workflows', query: { open: 'church-story' } })

        const button = wrapper.find('[data-testid="library-install"]')
        expect(button.text()).toBe('text.workflow.library.update_in_place')
        await button.trigger('click')
        await flushPromises()
        expect(api.installTemplate).toHaveBeenCalledWith('ispa.gospel', { asCopy: false })
        expect(router.push).toHaveBeenLastCalledWith({ name: 'workflows-setup', params: { project: 7 } })
    })

    it('shows a refused update and reads the template again', async () => {
        api.fetchTemplate.mockResolvedValue(detailOf({ key: 'ispa.gospel', installed: installedRow(), update_available: true, update_in_place: true }))
        api.installTemplate.mockRejectedValue({ response: { status: 409, data: { error_code: 'WORKFLOW_TEMPLATE_CHANGED' } } })
        const wrapper = await open()
        await pick(wrapper, 'ispa.gospel')
        const reads = api.fetchTemplate.mock.calls.length
        await wrapper.find('[data-testid="library-install"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="library-install-error"]').attributes('data-kind')).toBe('changed')
        expect(api.fetchTemplate.mock.calls.length).toBe(reads + 1)
        expect(wrapper.find('[data-testid="library-install"]').attributes('disabled')).toBeUndefined()
        expect(libraryError({ response: { status: 409, data: { error_code: 'WORKFLOW_TEMPLATE_UP_TO_DATE' } } }).kind).toBe('up_to_date')
    })

    it('opens the setup or the designer of a template that is installed and up to date', async () => {
        api.fetchTemplate.mockResolvedValue(detailOf({ key: 'ispa.seo', installed: installedRow({ project_id: 9, slug: 'seo', version: 20261201, version_label: '2026.12.01', is_active: true }) }))
        const wrapper = await open()
        await pick(wrapper, 'ispa.seo')
        expect(wrapper.find('[data-testid="library-install"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="library-detail-installed"]').text()).toContain('installed_on{"name":"Bài về nhà thờ"}')
        await wrapper.find('[data-testid="library-open-setup"]').trigger('click')
        expect(router.push).toHaveBeenLastCalledWith({ name: 'workflows-setup', params: { project: 'seo' } })
        await wrapper.find('[data-testid="library-open-designer"]').trigger('click')
        expect(router.push).toHaveBeenLastCalledWith({ name: 'workflows', query: { open: 'seo' } })
    })

    it('offers no install for a template whose module is off', async () => {
        api.fetchTemplate.mockResolvedValue(detailOf({ key: 'ispa.helpdesk', available: false, missing_modules: ['Helpdesk'] }))
        const wrapper = await open()
        await wrapper.find('[data-testid="library-only-available"] input').setValue(false)
        await pick(wrapper, 'ispa.helpdesk')
        expect(wrapper.find('[data-testid="library-detail-unavailable"]').text()).toContain('needs_modules_first{"modules":"Trung tâm trợ giúp"}')
        expect(wrapper.find('[data-testid="library-install"]').exists()).toBe(false)
    })

    it('refuses a template that fails the signature check and lets a failed check be tried again', async () => {
        api.fetchTemplate.mockRejectedValueOnce(refusal(422, 'WORKFLOW_BUNDLE_UNTRUSTED'))
        const wrapper = await open()
        await pick(wrapper, 'ispa.church-story')
        const alert = wrapper.find('[data-testid="library-detail-error"]')
        expect(alert.attributes('data-kind')).toBe('untrusted')
        expect(alert.text()).toContain('text.workflow.library.error.untrusted')
        expect(wrapper.find('[data-testid="library-detail-retry"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="library-install"]').exists()).toBe(false)

        await wrapper.find('[data-testid="drawer-close"]').trigger('click')
        api.fetchTemplate.mockRejectedValueOnce(refusal(503, 'WORKFLOW_HUB_UNREACHABLE'))
        await pick(wrapper, 'ispa.church-story')
        expect(wrapper.find('[data-testid="library-detail-error"]').attributes('data-kind')).toBe('unreachable')
        await wrapper.find('[data-testid="library-detail-retry"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="library-install"]').exists()).toBe(true)
    })

    it('keeps the button for another try when the hub drops during an install', async () => {
        api.installTemplate.mockRejectedValueOnce(refusal(503, 'WORKFLOW_HUB_UNREACHABLE')).mockResolvedValueOnce({ project_id: 12 })
        const wrapper = await open()
        await pick(wrapper, 'ispa.church-story')
        await wrapper.find('[data-testid="library-install"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[data-testid="library-install-error"]').attributes('data-kind')).toBe('unreachable')
        expect(router.push).not.toHaveBeenCalled()
        const button = wrapper.find('[data-testid="library-install"]')
        expect(button.attributes('disabled')).toBeUndefined()
        await button.trigger('click')
        await flushPromises()
        expect(router.push).toHaveBeenCalledWith({ name: 'workflows-setup', params: { project: 12 } })
    })

    it('names the missing modules and stops when an install is refused for them', async () => {
        api.installTemplate.mockRejectedValue(refusal(409, 'WORKFLOW_TEMPLATE_NOT_AVAILABLE', { missing_modules: ['Helpdesk'] }))
        const wrapper = await open()
        await pick(wrapper, 'ispa.church-story')
        await wrapper.find('[data-testid="library-install"]').trigger('click')
        await flushPromises()
        const alert = wrapper.find('[data-testid="library-install-error"]')
        expect(alert.attributes('data-kind')).toBe('not_available')
        expect(alert.text()).toContain('needs_modules_first{"modules":"Trung tâm trợ giúp"}')
        expect(wrapper.find('[data-testid="library-install"]').attributes('disabled')).toBeDefined()
    })

    it('lists in plain words why a template cannot be installed, without server codes', async () => {
        api.installTemplate.mockRejectedValue(
            refusal(422, 'WORKFLOW_TEMPLATE_NOT_INSTALLABLE', {
                problems: [
                    { code: 'unknown_node_type', step_code: 'write', message: 'Node type x.y is not registered' },
                    { code: 'brand_new_code', message: 'Raw server sentence' },
                ],
                binding_problems: [],
                slug_problems: [],
            })
        )
        const wrapper = await open()
        await pick(wrapper, 'ispa.church-story')
        await wrapper.find('[data-testid="library-install"]').trigger('click')
        await flushPromises()
        const alert = wrapper.find('[data-testid="library-install-error"]')
        expect(alert.attributes('data-kind')).toBe('not_installable')
        const lines = wrapper.findAll('[data-testid="library-install-problems"] li')
        expect(lines).toHaveLength(1)
        expect(lines[0].text()).toContain('text.workflow.package.problem.unknown_node_type')
        expect(lines[0].text()).toContain('AI viết bài')
        expect(alert.text()).not.toContain('Raw server sentence')
        expect(alert.text()).not.toContain('WORKFLOW_TEMPLATE_NOT_INSTALLABLE')
    })

    it('gives the keyboard focus back to the card when the drawer closes', async () => {
        const wrapper = await open()
        const card = wrapper.find('[data-key="ispa.church-story"]')
        await pick(wrapper, 'ispa.church-story')
        await wrapper.find('[data-testid="drawer-close"]').trigger('click')
        await flushPromises()
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
        expect(document.activeElement).toBe(card.element)
    })
})

describe('workflows list toolbar', () => {
    it('opens the template library for anyone who sees the list, and no longer offers the file import itself', async () => {
        const wrapper = mount(WorkflowsToolbar, {
            props: { filters: { search: '', status: 'all', tags: [] }, statusOptions: [], tagOptions: [], stats: { total: 0, active: 0, inactive: 0 } },
            global: { stubs: { ...stubs, 'a-input-search': true, 'a-select': true, 'a-switch': true, 'a-badge': true, 'a-card': true }, mocks: { $t: (key: string) => key } },
        })
        const button = wrapper.find('[data-testid="workflow-library"]')
        expect(button.text()).toBe('text.workflow.library.open_action')
        await button.trigger('click')
        expect(wrapper.emitted('library')).toHaveLength(1)
        expect(wrapper.find('[data-testid="workflow-import"]').exists()).toBe(false)
    })
})
