import { computed, inject, provide, ref, unref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import * as workflowApi from '@/services/workflowApi'
import { useNodeCatalog } from '@/components/workflows/nodeCatalog'
import { buildDataGroups, isPlaced, pathLabel } from './dataTokens.js'

/**
 * Data the step being edited may use, shared by every field of the step form.
 * The step settings panel calls `provideStepData` once; the data inputs read it with `useStepData`.
 */
const STEP_DATA = Symbol('workflow-step-data')

const TYPES = ['text', 'number', 'boolean', 'list', 'group', 'empty', 'data']
const ROOT_TYPES = { string: 'text', text: 'text', integer: 'number', int: 'number', bigint: 'number', float: 'number', decimal: 'number', boolean: 'boolean', bool: 'boolean', json: 'group', array: 'list' }

const safely = async (call) => {
    try {
        return await call()
    } catch (error) {
        return null
    }
}

/**
 * @param {{ nodes, edges, currentId, projectId, rootModel }} source Refs or getters of the designer graph, the step
 *        being edited, the workflow id (for the last run) and the record type the workflow runs on.
 */
export const provideStepData = (source) => {
    const { t, te } = useI18n()
    const read = (value) => (typeof value === 'function' ? value() : unref(value))

    const sample = ref(null)
    const sampleLoading = ref(false)
    const rootFields = ref([])
    const rootLoading = ref(false)

    watch(
        () => read(source.projectId),
        async (projectId) => {
            sample.value = null
            if (!projectId) return
            sampleLoading.value = true
            const context = await safely(() => workflowApi.fetchLastRunContext(projectId))
            if (read(source.projectId) === projectId) sample.value = context
            sampleLoading.value = false
        },
        { immediate: true }
    )

    watch(
        () => read(source.rootModel),
        async (model) => {
            rootFields.value = []
            if (!model || model === 'none') return
            rootLoading.value = true
            const schema = await safely(() => workflowApi.fetchModelSchema(model))
            if (read(source.rootModel) === model) {
                rootFields.value = (Array.isArray(schema?.fields) ? schema.fields : [])
                    .filter((field) => field?.name)
                    .map((field) => ({ name: field.name, type: ROOT_TYPES[String(field.type || '').toLowerCase()] || 'data' }))
            }
            rootLoading.value = false
        },
        { immediate: true }
    )

    // Fields of the record types the steps store (`dynamic: model` in the catalog), asked once per type.
    const { catalog } = useNodeCatalog()
    const modelFields = ref({})
    const askedModels = new Set()
    watch(
        () =>
            (read(source.nodes) || [])
                .filter((node) => catalog.byType?.[node?.data?.step_type]?.dynamic === 'model')
                .map((node) => node.data?.step_config?.model)
                .filter((model) => typeof model === 'string' && model !== '')
                .join('|'),
        (models) => {
            models.split('|').filter((model) => model && !askedModels.has(model)).forEach(async (model) => {
                askedModels.add(model)
                const schema = await safely(() => workflowApi.fetchModelSchema(model))
                const fields = (Array.isArray(schema?.fields) ? schema.fields : []).filter((field) => field?.name)
                if (fields.length) modelFields.value = { ...modelFields.value, [model]: fields }
            })
        },
        { immediate: true }
    )

    /** Authored wording of a declared result part: its own key first, then the text the catalog sent. */
    const partLabel = (part) => {
        if (part.label_key && te(part.label_key)) {
            const text = t(part.label_key)
            if (typeof text === 'string' && text !== part.label_key) return text
        }
        return ''
    }

    /** Authored wording of a data key (`text.workflow.field.<key>`), '' when the pack has none. */
    const label = (key) => {
        const name = `text.workflow.field.${key}`
        if (!te(name)) return ''
        const text = t(name)
        return typeof text === 'string' && text !== name ? text : ''
    }
    const roleLabel = (role) => t(`text.workflow.data.role_${role}`)

    const groups = computed(() =>
        buildDataGroups({
            nodes: read(source.nodes) || [],
            edges: read(source.edges) || [],
            currentId: read(source.currentId) ?? null,
            sample: sample.value,
            rootFields: rootFields.value,
            rootTitle: t('text.workflow.data.root_title'),
            label,
            byType: catalog.byType,
            modelFields: modelFields.value,
            partLabel,
        }).map((group) => ({
            ...group,
            items: group.items.map((item) => ({
                ...item,
                label: item.label || roleLabel(item.role || 'result'),
                typeLabel: t(`text.workflow.data.type_${TYPES.includes(item.type) ? item.type : 'data'}`),
                // A known value is named by the admin pack when it has the wording, else by the text the catalog sent.
                ...(item.values ? { values: item.values.map((value) => ({ value: value.value, label: partLabel(value) || value.label })) } : {}),
            })),
        }))
    )

    const state = {
        groups,
        loading: computed(() => sampleLoading.value || rootLoading.value),
        hasSample: computed(() => sample.value !== null),
        /** False while the step is not linked into the diagram: the order of the steps is then unknown. */
        placed: computed(() => isPlaced(read(source.nodes) || [], read(source.edges) || [], read(source.currentId) ?? null)),
        labelFor: (path) => pathLabel(path, groups.value, { roleLabel, label }),
        readOnly: computed(() => !!read(source.readOnly)),
        /** Steps of the diagram and the step being edited, for the settings that read another step. */
        nodes: computed(() => read(source.nodes) || []),
        currentId: computed(() => read(source.currentId) ?? null),
        /** Id of the workflow, for what a setting asks the server about its own step. */
        projectId: computed(() => read(source.projectId) ?? null),
    }
    provide(STEP_DATA, state)
    return state
}

/** The shared step data; outside a step form (a field used on its own) there is nothing to pick from. */
export const useStepData = () =>
    inject(STEP_DATA, {
        groups: computed(() => []),
        loading: computed(() => false),
        hasSample: computed(() => false),
        placed: computed(() => true),
        labelFor: (path) => pathLabel(path, []),
        readOnly: computed(() => false),
        nodes: computed(() => []),
        currentId: computed(() => null),
        projectId: computed(() => null),
    })

/**
 * The settings of the step being edited, shared by the form with the widgets that read or write more than their own
 * setting (the JEV criteria set also writes which preset it came from). `patch(values)` merges settings; a key
 * given as `undefined` is removed.
 */
const STEP_FORM = Symbol('workflow-step-form')
export const provideStepForm = (form) => provide(STEP_FORM, form)
export const useStepForm = () => inject(STEP_FORM, null)
