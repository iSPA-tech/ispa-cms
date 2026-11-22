/**
 * Moved from tests/ after the admin module split: widget management flows over the Content post pages and
 * Widget template sources, imported from ispa-cms/cms by relative path.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

// ---------------------------------------------------------------------------
// Shared, hoisted state used by the vi.mock factories below. vi.mock calls
// are hoisted above imports, so anything they reference must go through
// vi.hoisted().
// ---------------------------------------------------------------------------
const permissionState = vi.hoisted(() => ({
    denied: new Set<string>(),
}))

const routerState = vi.hoisted(() => ({
    route: {
        name: 'post-page',
        params: {} as Record<string, string>,
        query: {} as Record<string, string>,
    },
    push: vi.fn(),
}))

const hooksState = vi.hoisted(() => ({
    postsPayload: { data: [] as unknown[], perPage: 10, currentPage: 1, total: 0 },
    usePostsCall: null as null | {
        filters: Record<string, unknown>
        options: Record<string, unknown>
    },
    widgetsPayload: { data: [] as unknown[] },
    reloadWidgets: vi.fn(),
}))

const libsState = vi.hoisted(() => ({
    deletePost: vi.fn().mockResolvedValue({ status: 'success' }),
    getPost: vi.fn().mockResolvedValue({ status: 'success', data: {} }),
    quickPost: vi.fn().mockResolvedValue({ status: 'success' }),
    restorePost: vi.fn().mockResolvedValue({ status: 'success' }),
    getWidget: vi.fn(),
    storeWidget: vi.fn(),
    updateWidget: vi.fn(),
    deleteWidget: vi.fn(),
}))

const mixinsState = vi.hoisted(() => ({
    iAlert: vi.fn(),
}))

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------
vi.mock('vue-router', () => ({
    useRoute: () => routerState.route,
    useRouter: () => ({ push: routerState.push, back: vi.fn() }),
}))

vi.mock('@/config', () => ({
    CACHE_KEY_ACCOUNT_DATA: 'CACHE_KEY_ACCOUNT_DATA',
    CACHE_KEY_MESSAGES_LOCALE: 'CACHE_KEY_MESSAGES_LOCALE',
    CONTENT_STATUSES: {},
    COOKIE_KEY_LOCALE: 'iSpa-locale',
    defaultLocale: 'en',
    basePath: '/iPanel/',
    apiUrl: 'https://example.com/',
    url: 'https://example.com/',
    cookie: { get: vi.fn(), set: vi.fn(), remove: vi.fn(), getAll: vi.fn() },
    CATEGORY_TYPE_STR_POST: 'article',
    CATEGORY_TYPE: {
        article: 0,
        product: 4,
        gallery: 2,
        place: 1,
        event: 3,
        upload: 5,
    },
}))

// `can`/`rule` are the same permission API (`rule` is a deprecated alias of
// `can`). Both are replaced with a controllable evaluator keyed by the
// resolved "resource.action" string, driven by `permissionState.denied`.
vi.mock('@/mixins', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()

    const specToKey = (spec: unknown): string => {
        if (typeof spec === 'string') return spec
        if (spec && typeof spec === 'object') {
            const s = spec as Record<string, string>
            if (s.t === 'legacy') return `${s.r}.${s.a}`
            if (s.t === 'action') return `${s.m}.${s.r}.${s.a}`
            if (s.t === 'resource') return `${s.m}.${s.r}`
            if (s.t === 'module') return s.m
        }
        return String(spec)
    }

    const evaluate = (key: string) => !permissionState.denied.has(key)

    const canOrRule = (a: unknown, b?: string, c?: string) => {
        if (c !== undefined) return evaluate(`${a}.${b}.${c}`)
        if (b !== undefined) return evaluate(`${a}.${b}`)
        return evaluate(specToKey(a))
    }

    return {
        ...actual,
        can: canOrRule,
        rule: canOrRule,
        iAlert: mixinsState.iAlert,
        finishFailed: vi.fn(),
        getInputVariableByTemplate: (inputs: unknown[]) => inputs,
    }
})

vi.mock('@/hooks', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()

    return {
        ...actual,
        // `data`/`isLoading`/`isFetching` are read directly in <template> for
        // several of these pages, which relies on Vue's script-setup ref
        // auto-unwrap. A plain `{ value: ... }` object is NOT a real Ref and
        // will not auto-unwrap, so real `ref()` instances are required here.
        usePosts: (filters: Record<string, unknown>, options: Record<string, unknown>) => {
            hooksState.usePostsCall = { filters, options }
            return {
                data: ref(hooksState.postsPayload),
                refetch: vi.fn(),
                isLoading: ref(false),
                isFetching: ref(false),
            }
        },
        useCategories: () => ({ data: ref({}) }),
        useEnumerates: () => ({ data: ref([]) }),
        useQuickPost: () => ({ mutate: vi.fn() }),
        useWidgets: () => ({
            data: ref(hooksState.widgetsPayload),
            refetch: hooksState.reloadWidgets,
        }),
    }
})

vi.mock('@/libs', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()

    return {
        ...actual,
        deletePost: libsState.deletePost,
        getPost: libsState.getPost,
        quickPost: libsState.quickPost,
        restorePost: libsState.restorePost,
        getWidget: libsState.getWidget,
        storeWidget: libsState.storeWidget,
        updateWidget: libsState.updateWidget,
        deleteWidget: libsState.deleteWidget,
    }
})

// The design editor (now part of the Core widget library) asks whether hub widgets are edited in place here.
vi.mock('../../../../cms/Core/admin/widget-library-api', () => ({
    getCatalogStatus: vi.fn().mockResolvedValue({ authoring: false, unexported_widget_keys: [] }),
    forgetWidgetThumbnails: vi.fn(),
    forgetPlaceableDesigns: vi.fn(),
}))

vi.mock('ant-design-vue', async (importOriginal) => {
    const actual = await importOriginal<Record<string, unknown>>()

    return {
        ...actual,
        message: { success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() },
    }
})

// ---------------------------------------------------------------------------
// Components under test (imported after the mocks above)
// ---------------------------------------------------------------------------
import PostPage from '../../../../cms/Content/admin/pages/pages.vue'
import PostUpdate from '../../../../cms/Content/admin/pages/update.vue'
import SettingWidget from '../../../../cms/Core/admin/pages/widget-library/sources.vue'
import WidgetTemplate from '../../../../cms/Core/admin/components/widget-source/widget-template.vue'
import WidgetInput from '../../../../cms/Core/admin/components/widget-source/widget-input.vue'
import { buildWidgetSavePayload } from '@/libs'
import { message as antdMessage } from 'ant-design-vue'

// ---------------------------------------------------------------------------
// Reusable lightweight stubs standing in for ant-design-vue components that
// aren't installed as a plugin in this unit-test environment. They preserve
// just enough real behaviour (scoped slots, click -> confirm) to exercise
// the business logic declared in the pages/components under test.
// ---------------------------------------------------------------------------
const ATableStub = defineComponent({
    name: 'ATableStub',
    props: {
        dataSource: { type: Array, default: () => [] },
        columns: { type: Array, default: () => [] },
    },
    setup(props, { slots }) {
        return () =>
            h(
                'div',
                { 'data-test': 'a-table-stub' },
                (props.dataSource as Array<Record<string, unknown>>).map((row, index) =>
                    h(
                        'div',
                        {
                            key: (row.id as number) ?? index,
                            'data-test': `table-row-${row.id ?? index}`,
                        },
                        (props.columns as Array<Record<string, unknown>>).map((column) =>
                            slots.bodyCell ? slots.bodyCell({ column, record: row, index }) : null
                        )
                    )
                )
            )
    },
})

const APopconfirmStub = defineComponent({
    name: 'APopconfirmStub',
    emits: ['confirm'],
    setup(_props, { slots, emit }) {
        return () => h('span', { onClick: () => emit('confirm') }, slots.default?.())
    },
})

const AModalStub = defineComponent({
    name: 'AModalStub',
    setup(_props, { slots }) {
        return () => h('div', {}, [slots.default?.(), slots.footer?.()])
    },
})

const AFormStub = defineComponent({
    name: 'AFormStub',
    emits: ['finish', 'finish-failed'],
    setup(_props, { slots, emit }) {
        return () =>
            h(
                'form',
                {
                    onSubmit: (event: Event) => {
                        event.preventDefault()
                        emit('finish', {})
                    },
                },
                slots.default?.()
            )
    },
})

// =============================================================================
// E. Aggregate Save payload (pure function)
// =============================================================================
describe('buildWidgetSavePayload', () => {
    it('omits deleted_template_ids/deleted_input_ids when nothing is staged', () => {
        const form = {
            id: 5,
            name: 'Hero',
            template: [{ id: 1, name: 'T1' }],
            inputs: [{ id: 2, name: 'I1' }],
        }

        const payload = buildWidgetSavePayload(form)

        expect(payload.template).toEqual([{ id: 1, name: 'T1' }])
        expect(payload.inputs).toEqual([{ id: 2, name: 'I1' }])
        expect(payload).not.toHaveProperty('deleted_template_ids')
        expect(payload).not.toHaveProperty('deleted_input_ids')
    })

    it('excludes staged rows from the arrays and reports their ids in deleted_*_ids', () => {
        const form = {
            id: 5,
            template: [
                { id: 1, name: 'T1' },
                { id: 2, name: 'T2', _deleted: true },
            ],
            inputs: [
                { id: 3, name: 'I1', _deleted: true },
                { id: 4, name: 'I2' },
            ],
        }

        const payload = buildWidgetSavePayload(form)

        expect(payload.template).toEqual([{ id: 1, name: 'T1' }])
        expect(payload.inputs).toEqual([{ id: 4, name: 'I2' }])
        expect(payload.deleted_template_ids).toEqual([2])
        expect(payload.deleted_input_ids).toEqual([3])
    })

    it('does not stage unsaved (id 0) rows even if marked deleted', () => {
        const form = {
            id: 0,
            template: [{ id: 0, name: 'Draft', _deleted: true }],
            inputs: [],
        }

        const payload = buildWidgetSavePayload(form)

        expect(payload.template).toEqual([])
        expect(payload).not.toHaveProperty('deleted_template_ids')
        expect(payload).not.toHaveProperty('deleted_input_ids')
    })

    it('strips the local _deleted marker from wire rows (post-undo rows stay clean)', () => {
        const form = {
            id: 5,
            template: [{ id: 1, name: 'T1', _deleted: false }],
            inputs: [{ id: 2, name: 'I1', _deleted: false }],
        }

        const payload = buildWidgetSavePayload(form)

        expect(payload.template).toEqual([{ id: 1, name: 'T1' }])
        expect(payload.inputs).toEqual([{ id: 2, name: 'I1' }])
        expect(payload.template[0]).not.toHaveProperty('_deleted')
        expect(payload.inputs[0]).not.toHaveProperty('_deleted')
        expect(payload).not.toHaveProperty('deleted_template_ids')
        expect(payload).not.toHaveProperty('deleted_input_ids')
    })

    it('reports rebuild-dropped persisted inputs alongside staged deletions (real rebuild, no identity mock)', async () => {
        // Use the REAL rebuild, bypassing this file's identity mock, to prove
        // the orphan scenario end to end: with deletion fields present the
        // backend sweep is disabled, so a persisted input silently dropped by
        // the rebuild must still be reported in deleted_input_ids.
        const { getInputVariableByTemplate: realRebuild } = (await vi.importActual('@/mixins')) as {
            getInputVariableByTemplate: (
                inputs: Array<Record<string, unknown>>,
                templates: Array<Record<string, unknown>>
            ) => Array<Record<string, unknown>>
        }

        // Server truth: two persisted inputs.
        const persistedInputs = [
            { id: 2, widget_id: 7, name: 'title', type: 'text', default_value: null, options: [] },
            {
                id: 3,
                widget_id: 7,
                name: 'subtitle',
                type: 'text',
                default_value: null,
                options: [],
            },
        ]
        // The user edited the template so it no longer references {subtitle}.
        const editedTemplate = [
            {
                id: 1,
                widget_id: 7,
                name: 'Main',
                content: '<h1>{title}</h1>',
                css: '',
                query: [],
                project_name: [],
            },
        ]

        // The rebuild fires on every form @change and silently drops `subtitle`.
        const rebuiltInputs = realRebuild(persistedInputs, editedTemplate)
        expect(rebuiltInputs.map((input) => input.id)).toEqual([2])

        // The user ALSO explicitly stages the surviving input for deletion.
        rebuiltInputs[0]._deleted = true

        const payload = buildWidgetSavePayload(
            { id: 7, name: 'Hero', template: editedTemplate, inputs: rebuiltInputs },
            { templateIds: [1], inputIds: [2, 3] }
        )

        expect(payload.inputs).toEqual([])
        // Both the staged id (2) and the rebuild-dropped id (3) are reported.
        expect([...payload.deleted_input_ids].sort()).toEqual([2, 3])
        // The template survived: snapshot minus surviving is empty, nothing staged.
        expect(payload).not.toHaveProperty('deleted_template_ids')
    })
})

// =============================================================================
// D + C. WidgetTemplate: staged delete, undo, widget_theme.* permission gates
// =============================================================================
describe('WidgetTemplate staged deletion + undo + permissions', () => {
    beforeEach(() => {
        permissionState.denied.clear()
    })

    const mountTemplate = (modelValue: Array<Record<string, unknown>>) =>
        mount(WidgetTemplate, {
            props: { modelValue },
            global: {
                stubs: {
                    'a-table': ATableStub,
                    'a-popconfirm': APopconfirmStub,
                    WidgetTemplateForm: true,
                },
            },
        })

    it('stages a persisted row as deleted instead of removing it, and offers restore', async () => {
        const modelValue = [
            {
                id: 10,
                widget_id: 7,
                name: 'Hero',
                content: 'c',
                css: '',
                query: {},
                project_name: [],
            },
        ]
        const wrapper = mountTemplate(modelValue)

        await wrapper.get('[data-test="template-delete-10"]').trigger('click')

        expect(modelValue[0]._deleted).toBe(true)
        expect(modelValue).toHaveLength(1)
        expect(wrapper.find('[data-test="template-undo-10"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="template-delete-10"]').exists()).toBe(false)
    })

    it('restores a staged row on undo', async () => {
        const modelValue = [
            {
                id: 10,
                widget_id: 7,
                name: 'Hero',
                content: 'c',
                css: '',
                query: {},
                project_name: [],
                _deleted: true,
            },
        ]
        const wrapper = mountTemplate(modelValue)

        await wrapper.get('[data-test="template-undo-10"]').trigger('click')

        expect(modelValue[0]._deleted).toBe(false)
        expect(wrapper.find('[data-test="template-delete-10"]').exists()).toBe(true)
    })

    it('drops unsaved (id 0) rows immediately instead of staging them', async () => {
        const modelValue = [
            {
                id: 0,
                widget_id: 0,
                name: 'Draft',
                content: 'c',
                css: '',
                query: {},
                project_name: [],
            },
        ]
        const wrapper = mountTemplate(modelValue)

        await wrapper.get('[data-test="template-delete-0"]').trigger('click')

        expect(modelValue).toHaveLength(0)
    })

    it('hides Add/Edit/Delete without widget_theme create/update/delete permissions', () => {
        permissionState.denied.add('widget_theme.create')
        permissionState.denied.add('widget_theme.update')
        permissionState.denied.add('widget_theme.delete')

        const modelValue = [
            {
                id: 10,
                widget_id: 7,
                name: 'Hero',
                content: 'c',
                css: '',
                query: {},
                project_name: [],
            },
        ]
        const wrapper = mountTemplate(modelValue)

        expect(wrapper.find('[data-test="template-add"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="template-edit-10"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="template-delete-10"]').exists()).toBe(false)
    })
})

// =============================================================================
// D + C. WidgetInput: staged delete, undo, widget_input.* permission gates
// =============================================================================
describe('WidgetInput staged deletion + undo + permissions', () => {
    beforeEach(() => {
        permissionState.denied.clear()
    })

    const mountInput = (modelValue: Array<Record<string, unknown>>) =>
        mount(WidgetInput, {
            props: { modelValue },
            global: {
                stubs: {
                    'a-table': ATableStub,
                    'a-popconfirm': APopconfirmStub,
                    WidgetInputForm: true,
                },
            },
        })

    it('stages a persisted row as deleted instead of removing it, and offers restore', async () => {
        const modelValue = [
            { id: 20, widget_id: 7, name: 'Title', type: 'text', default_value: null, options: [] },
        ]
        const wrapper = mountInput(modelValue)

        await wrapper.get('[data-test="input-delete-20"]').trigger('click')

        expect(modelValue[0]._deleted).toBe(true)
        expect(modelValue).toHaveLength(1)
        expect(wrapper.find('[data-test="input-undo-20"]').exists()).toBe(true)
    })

    it('restores a staged row on undo', async () => {
        const modelValue = [
            {
                id: 20,
                widget_id: 7,
                name: 'Title',
                type: 'text',
                default_value: null,
                options: [],
                _deleted: true,
            },
        ]
        const wrapper = mountInput(modelValue)

        await wrapper.get('[data-test="input-undo-20"]').trigger('click')

        expect(modelValue[0]._deleted).toBe(false)
    })

    it('hides Edit/Delete without widget_input update/delete permissions', () => {
        permissionState.denied.add('widget_input.update')
        permissionState.denied.add('widget_input.delete')

        const modelValue = [
            { id: 20, widget_id: 7, name: 'Title', type: 'text', default_value: null, options: [] },
        ]
        const wrapper = mountInput(modelValue)

        expect(wrapper.find('[data-test="input-edit-20"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="input-delete-20"]').exists()).toBe(false)
    })
})

// =============================================================================
// C + D + E. SettingWidget (widget.vue): permission gating, Cancel, Save payload
// =============================================================================
describe('SettingWidget (widget.vue)', () => {
    const mountWidget = () =>
        mount(SettingWidget, {
            shallow: true,
            global: {
                stubs: {
                    'a-table': ATableStub,
                    'a-modal': AModalStub,
                    'a-form': AFormStub,
                    'a-popconfirm': APopconfirmStub,
                    // The relocated page wraps its toolbar in the module page
                    // header; shallow stubs swallow slots, so this stub passes
                    // the #actions slot through (the create button lives there).
                    SiteLayoutPageHeader: {
                        template: '<div><slot name="actions" /></div>',
                    },
                },
            },
        })

    beforeEach(() => {
        permissionState.denied.clear()
        hooksState.widgetsPayload = {
            data: [{ id: 7, name: 'Hero Banner', template: [], inputs: [], has_js: false }],
        }
        hooksState.reloadWidgets.mockClear()
        libsState.getWidget.mockReset()
        libsState.storeWidget
            .mockReset()
            .mockResolvedValue({ status: 'success', data: { id: 99, sync_status: 'ready' } })
        libsState.updateWidget
            .mockReset()
            .mockResolvedValue({ status: 'success', data: { id: 7, sync_status: 'ready' } })
        libsState.deleteWidget.mockReset().mockResolvedValue({ status: 'success' })
        mixinsState.iAlert.mockClear()
        ;(antdMessage.warning as ReturnType<typeof vi.fn>).mockClear()
    })

    it('hides the create button without widget.create', () => {
        permissionState.denied.add('widget.create')
        const wrapper = mountWidget()
        expect(wrapper.find('[data-test="widget-create"]').exists()).toBe(false)
    })

    it('shows the create button with widget.create', () => {
        const wrapper = mountWidget()
        expect(wrapper.find('[data-test="widget-create"]').exists()).toBe(true)
    })

    it('hides row edit/delete controls without widget.update/widget.delete', () => {
        permissionState.denied.add('widget.update')
        permissionState.denied.add('widget.delete')
        const wrapper = mountWidget()
        expect(wrapper.find('[data-test="widget-edit-7"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="widget-delete-7"]').exists()).toBe(false)
    })

    it('deletes a widget by numeric id (widgets have no uuid column)', async () => {
        const wrapper = mountWidget()

        await wrapper.get('[data-test="widget-delete-7"]').trigger('click')

        expect(libsState.deleteWidget).toHaveBeenCalledWith(7)
    })

    describe('save payload + sync status', () => {
        beforeEach(() => {
            libsState.getWidget.mockResolvedValue({
                status: 'success',
                data: {
                    id: 7,
                    name: 'Hero Banner',
                    description: 'desc',
                    js: '',
                    template: [
                        {
                            id: 1,
                            widget_id: 7,
                            name: 'T1',
                            content: 'c',
                            css: '',
                            query: {},
                            project_name: [],
                            _deleted: true,
                        },
                    ],
                    inputs: [
                        {
                            id: 2,
                            widget_id: 7,
                            name: 'I1',
                            type: 'text',
                            default_value: null,
                            options: [],
                        },
                    ],
                },
            })
        })

        it('includes deleted_template_ids and excludes staged rows when a deletion is staged', async () => {
            const wrapper = mountWidget()

            await wrapper.get('[data-test="widget-edit-7"]').trigger('click')
            await flushPromises()

            await wrapper.get('form').trigger('submit')
            await flushPromises()

            expect(libsState.updateWidget).toHaveBeenCalledTimes(1)
            const [id, payload] = libsState.updateWidget.mock.calls[0]
            expect(id).toBe(7)
            expect(payload.template).toEqual([])
            expect(payload.deleted_template_ids).toEqual([1])
            expect(payload).not.toHaveProperty('deleted_input_ids')
        })

        it('omits deleted_*_ids when nothing is staged', async () => {
            libsState.getWidget.mockResolvedValue({
                status: 'success',
                data: {
                    id: 7,
                    name: 'Hero Banner',
                    description: 'desc',
                    js: '',
                    template: [
                        {
                            id: 1,
                            widget_id: 7,
                            name: 'T1',
                            content: 'c',
                            css: '',
                            query: {},
                            project_name: [],
                        },
                    ],
                    inputs: [
                        {
                            id: 2,
                            widget_id: 7,
                            name: 'I1',
                            type: 'text',
                            default_value: null,
                            options: [],
                        },
                    ],
                },
            })

            const wrapper = mountWidget()
            await wrapper.get('[data-test="widget-edit-7"]').trigger('click')
            await flushPromises()

            await wrapper.get('form').trigger('submit')
            await flushPromises()

            const [, payload] = libsState.updateWidget.mock.calls[0]
            expect(payload).not.toHaveProperty('deleted_template_ids')
            expect(payload).not.toHaveProperty('deleted_input_ids')
        })

        it('treats sync_status "pending" as success and surfaces a non-blocking notice with the incident id', async () => {
            libsState.updateWidget.mockResolvedValue({
                status: 'success',
                data: { id: 7, sync_status: 'pending', sync_incident_id: 'INC-42' },
            })

            const wrapper = mountWidget()
            await wrapper.get('[data-test="widget-edit-7"]').trigger('click')
            await flushPromises()

            await wrapper.get('form').trigger('submit')
            await flushPromises()

            expect(antdMessage.warning).toHaveBeenCalledTimes(1)
            expect(
                String((antdMessage.warning as ReturnType<typeof vi.fn>).mock.calls[0][0])
            ).toContain('INC-42')
            // Pending sync is still a committed success: modal closes and list reloads.
            expect(hooksState.reloadWidgets).toHaveBeenCalled()
        })

        it('discards staged deletions on Cancel so the next Save omits deletion fields', async () => {
            const wrapper = mountWidget()

            await wrapper.get('[data-test="widget-edit-7"]').trigger('click')
            await flushPromises()

            await wrapper.get('[data-test="widget-cancel"]').trigger('click')

            await wrapper.get('form').trigger('submit')
            await flushPromises()

            expect(libsState.storeWidget).toHaveBeenCalledTimes(1)
            expect(libsState.updateWidget).not.toHaveBeenCalled()
            const [payload] = libsState.storeWidget.mock.calls[0]
            expect(payload).not.toHaveProperty('deleted_template_ids')
            expect(payload.id).toBe(0)
        })

        it('surfaces a non-success save response via iAlert and keeps staged intent uncommitted', async () => {
            const errorResponse = { status: 'error', message: 'Save rejected' }
            libsState.updateWidget.mockResolvedValue(errorResponse)

            const wrapper = mountWidget()
            await wrapper.get('[data-test="widget-edit-7"]').trigger('click')
            await flushPromises()

            await wrapper.get('form').trigger('submit')
            await flushPromises()

            expect(mixinsState.iAlert).toHaveBeenCalledWith(errorResponse)
            // Modal stays open (no reload) so staged deletions remain visible.
            expect(hooksState.reloadWidgets).not.toHaveBeenCalled()
        })

        it('surfaces transport errors from Save via iAlert(err, true)', async () => {
            const failure = new Error('network down')
            libsState.updateWidget.mockRejectedValue(failure)

            const wrapper = mountWidget()
            await wrapper.get('[data-test="widget-edit-7"]').trigger('click')
            await flushPromises()

            await wrapper.get('form').trigger('submit')
            await flushPromises()

            expect(mixinsState.iAlert).toHaveBeenCalledWith(failure, true)
            expect(hooksState.reloadWidgets).not.toHaveBeenCalled()
        })
    })
})

// =============================================================================
// A. Page query enablement (pages.vue)
// =============================================================================
describe('PostPage (pages.vue) query enablement', () => {
    beforeEach(() => {
        permissionState.denied.clear()
        hooksState.usePostsCall = null
        hooksState.postsPayload = { data: [], perPage: 10, currentPage: 1, total: 0 }
    })

    it('enables the posts query and filters by type=page for the post-page route', () => {
        routerState.route = { name: 'post-page', params: {}, query: {} }

        mount(PostPage, { shallow: true })

        expect(hooksState.usePostsCall?.options.enabled).toBe(true)
        expect(hooksState.usePostsCall?.filters.type).toBe('page')
    })

    it('does not enable the query for a route outside the allow-list', () => {
        routerState.route = { name: 'post-unrelated', params: {}, query: {} }

        mount(PostPage, { shallow: true })

        expect(hooksState.usePostsCall?.options.enabled).toBe(false)
    })
})

// =============================================================================
// B. Page update permission (update.vue)
// =============================================================================
describe('PostUpdate (update.vue) permission key', () => {
    const provideValues = () => ({
        form: ref({
            id: 5,
            type: 'page',
            name: 'Home',
            content: [],
            images: [],
            attachments: [],
            config: {},
            seos: [],
            video_url: null,
            parent_id: null,
            language_id: null,
            status: 'status_published',
            published_at: null,
        }),
        formRef: ref(null),
        setForm: vi.fn(),
        submitForm: vi.fn(),
        btnState: ref(false),
        getLimitImageByPostType: () => 1,
        checkFormChanged: vi.fn(),
        postMedia: () => [],
    })

    beforeEach(() => {
        permissionState.denied.clear()
        routerState.route = { name: 'post-update', params: { uuid: 'widget-uuid' }, query: {} }
        libsState.getPost.mockResolvedValue({ status: 'success', data: { id: 5, type: 'page' } })
    })

    const mountUpdate = () =>
        mount(PostUpdate, {
            shallow: true,
            global: { provide: provideValues() },
        })

    it('renders the edit form when the user has post.update, even without post.create', () => {
        permissionState.denied.add('post.create')

        const wrapper = mountUpdate()

        expect(wrapper.find('a-form').exists()).toBe(true)
        expect(wrapper.find('no-permission-error').exists()).toBe(false)
    })

    it('hides the edit form and shows no-permission-error without post.update', () => {
        permissionState.denied.add('post.update')

        const wrapper = mountUpdate()

        expect(wrapper.find('a-form').exists()).toBe(false)
        expect(wrapper.find('no-permission-error').exists()).toBe(true)
    })

    it('preserves existing text content when form validation returns only video fields', async () => {
        const content = [
            { id: 'content_0', type: 'text', value: '<p>Nội dung cũ</p>' },
            {
                id: 'content_1',
                type: 'video',
                value: 'https://www.youtube.com/watch?v=abc123',
                options: { desktopHeight: 450, mobileHeight: 300 },
            },
        ]
        const form = ref({
            ...provideValues().form.value,
            content,
        })
        const submitForm = vi.fn()

        const wrapper = mount(PostUpdate, {
            shallow: true,
            global: {
                provide: {
                    ...provideValues(),
                    form,
                    submitForm,
                },
            },
        })

        await flushPromises()
        wrapper.vm.manualSubmitForm({
            content: [
                {
                    value: 'https://www.youtube.com/watch?v=abc123',
                    options: { desktopHeight: 450, mobileHeight: 300 },
                },
            ],
        })

        expect(submitForm).toHaveBeenCalledWith(expect.objectContaining({ content }))
        expect(form.value.content).toEqual(content)
    })
})
