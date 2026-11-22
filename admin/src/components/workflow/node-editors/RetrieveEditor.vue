<template>
    <div class="retrieve-editor space-y-3">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700 mb-1">
                {{ t('text.workflow.editor.retrieve.model') }}
            </p>
            <a-segmented
                v-model:value="config.sourceType"
                :options="sourceTypeOptions"
                size="small"
                @change="onSourceTypeChange"
            />
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-start">
            <div class="space-y-2 flex flex-col">
                <div>
                    <template v-if="config.sourceType === 'model'">
                        <a-select
                            v-model:value="config.model"
                            :disabled="readOnly"
                            :options="combinedModelOptions"
                            allow-clear
                            mode="combobox"
                            :placeholder="t('text.workflow.editor.retrieve.model_placeholder')"
                            show-search
                            @change="emitConfig"
                        />
                        <div class="flex items-center gap-2 text-xs">
                            <a-button
                                :disabled="readOnly || !currentModelClass"
                                size="small"
                                type="link"
                                @click="useCurrentModel"
                            >
                                {{
                                    t('text.workflow.editor.use_current_model', {
                                        value:
                                            currentModelClass ||
                                            t('text.workflow.editor.no_current_model'),
                                    })
                                }}
                            </a-button>
                        </div>
                    </template>

                    <template v-else>
                        <a-select
                            v-model:value="config.relation"
                            :disabled="readOnly || !relationOptions.length"
                            :options="relationOptions"
                            allow-clear
                            :placeholder="t('text.workflow.editor.retrieve.relation_placeholder')"
                            show-search
                            @change="onRelationSelect"
                        />
                        <p class="text-xs text-gray-500">
                            {{ t('text.workflow.editor.retrieve.relation_help') }}
                        </p>
                    </template>
                </div>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">
                    {{ t('text.workflow.editor.store_as') }}
                </p>
                <a-input
                    v-model:value="config.storeAs"
                    :disabled="readOnly"
                    :placeholder="t('text.workflow.editor.retrieve.store_as_placeholder')"
                    @change="emitConfig"
                />
                <p :class="storeAsValid ? 'text-green-600' : 'text-red-600'" class="text-xs">
                    {{ t('text.workflow.editor.store_as_hint_required') }}
                </p>
            </div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-gray-700">
                    {{ t('text.workflow.editor.retrieve.where') }}
                </p>
                <a-button :disabled="readOnly" size="small" @click="addWhere">
                    {{ t('text.workflow.editor.retrieve.add_filter') }}
                </a-button>
            </div>
            <div v-if="!config.where.length" class="text-xs text-gray-500">
                {{ t('text.workflow.editor.retrieve.no_filters_yet') }}
            </div>
            <div
                v-for="(w, idx) in config.where"
                :key="w.__id"
                class="rounded border border-gray-200 p-3 bg-gray-50 space-y-2"
            >
                <div class="grid grid-cols-1 md:grid-cols-6 gap-2 items-center">
                    <a-select
                        v-if="fieldOptions.length"
                        v-model:value="w.field"
                        :disabled="readOnly"
                        :options="fieldOptions"
                        class="md:col-span-2"
                        :placeholder="t('validation.attributes.field')"
                        show-search
                        @change="emitConfig"
                    />
                    <a-input
                        v-else
                        v-model:value="w.field"
                        :disabled="readOnly"
                        class="md:col-span-2"
                        :placeholder="t('text.workflow.editor.create_object.field_placeholder')"
                        @change="emitConfig"
                    />
                    <a-select
                        v-model:value="w.op"
                        :disabled="readOnly"
                        :options="opOptions"
                        class="md:col-span-1"
                        @change="emitConfig"
                    />
                    <ExpressionEditor
                        v-model:model-value="w.valueExpr"
                        :available-vars="availableVars"
                        :model-class="config.model || currentModelClass"
                        :placeholder="'root.customer_email'"
                        class="md:col-span-3"
                    />
                </div>
                <div class="flex justify-end">
                    <a-button :disabled="readOnly" danger size="small" @click="removeWhere(idx)">
                        {{ t('text.workflow.editor.remove') }}
                    </a-button>
                </div>
            </div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-gray-700">
                    {{ t('text.workflow.editor.retrieve.order_by') }}
                </p>
                <a-button :disabled="readOnly" size="small" @click="addOrder">
                    {{ t('text.workflow.editor.retrieve.add_order') }}
                </a-button>
            </div>
            <div v-if="!config.orderBy.length" class="text-xs text-gray-500">
                {{ t('text.workflow.editor.retrieve.no_ordering') }}
            </div>
            <div
                v-for="(o, idx) in config.orderBy"
                :key="o.__id"
                class="grid grid-cols-1 md:grid-cols-6 gap-2 items-center"
            >
                <a-select
                    v-if="fieldOptions.length"
                    v-model:value="o.field"
                    :disabled="readOnly"
                    :options="fieldOptions"
                    class="md:col-span-3"
                    :placeholder="t('validation.attributes.field')"
                    show-search
                    @change="emitConfig"
                />
                <a-input
                    v-else
                    v-model:value="o.field"
                    :disabled="readOnly"
                    class="md:col-span-3"
                    :placeholder="t('text.workflow.editor.create_object.field_placeholder')"
                    @change="emitConfig"
                />
                <a-select
                    v-model:value="o.dir"
                    :disabled="readOnly"
                    :options="dirOptions"
                    class="md:col-span-2"
                    @change="emitConfig"
                />
                <div class="md:col-span-6 flex justify-end">
                    <a-button :disabled="readOnly" danger size="small" @click="removeOrder(idx)">
                        {{ t('text.workflow.editor.remove') }}
                    </a-button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-center">
            <div class="flex items-center gap-2">
                <a-checkbox
                    v-model:checked="config.first"
                    :disabled="readOnly"
                    @change="emitConfig"
                >
                    {{ t('text.workflow.editor.retrieve.return_first') }}
                </a-checkbox>
                <a-checkbox
                    v-model:checked="config.includeTrashed"
                    :disabled="readOnly"
                    @change="emitConfig"
                >
                    {{ t('text.workflow.editor.retrieve.include_trashed') }}
                </a-checkbox>
            </div>
            <div v-if="!config.first" class="flex items-center gap-2">
                <span class="text-sm text-gray-700">
                    {{ t('text.workflow.editor.retrieve.limit') }}
                </span>
                <a-input-number
                    v-model:value="config.limit"
                    :disabled="readOnly"
                    :min="1"
                    style="width: 120px"
                    @change="emitConfig"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { nanoid } from 'nanoid'
import { useI18n } from 'vue-i18n'
import { fetchModelList, fetchModelSchema } from '@/services/workflowApi'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'
import { getUpstreamStoreAsKeys } from '@/libs/utils'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
    modelClass: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const availableVars = computed(() => getUpstreamStoreAsKeys(activeNodeId))

const isHydrating = ref(true)

const config = reactive({
    model: '',
    sourceType: 'model', // 'model' | 'relation'
    relation: '',
    where: [],
    orderBy: [],
    first: true,
    limit: 1,
    storeAs: 'vars.result',
    includeTrashed: false,
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        isHydrating.value = true
        Object.assign(config, {
            model: val?.model || '',
            sourceType: val?.sourceType || (val?.relation ? 'relation' : 'model'),
            relation: val?.relation || '',
            where: (val?.where || []).map((w) => ({ __id: nanoid(), ...w })),
            orderBy: (val?.orderBy || []).map((o) => ({ __id: nanoid(), ...o })),
            first: val?.first ?? true,
            limit: val?.limit ?? 1,
            storeAs: val?.storeAs || 'vars.',
        })
        isHydrating.value = false
    },
    { immediate: true, deep: true }
)

const currentModelClass = computed(() => props.modelClass || '')
const sourceTypeOptions = computed(() => [
    { label: t('text.workflow.editor.retrieve.source_type.model'), value: 'model' },
    { label: t('text.workflow.editor.retrieve.source_type.relation'), value: 'relation' },
])

const modelOptions = ref([])

const combinedModelOptions = computed(() => {
    const opts = []
    const seen = new Set()

    if (config.model) {
        opts.push({ label: config.model, value: config.model })
        seen.add(config.model)
    }
    if (currentModelClass.value && !seen.has(currentModelClass.value)) {
        opts.push({ label: currentModelClass.value, value: currentModelClass.value })
        seen.add(currentModelClass.value)
    }

    modelOptions.value.forEach((opt) => {
        if (opt?.value && !seen.has(opt.value)) {
            opts.push(opt)
            seen.add(opt.value)
        }
    })

    return opts
})

const fieldOptions = computed(() =>
    (schema.fields || []).map((f) => ({
        label: f.name,
        value: f.name,
    }))
)
const rootSchema = ref(null)
const rootRelations = computed(() => rootSchema.value?.relations || [])
const relationOptions = computed(() =>
    rootRelations.value.map((rel) => ({
        label: `${rel.name} → ${rel.model}`,
        value: rel.name,
    }))
)

const opOptions = [
    { label: '==', value: '==' },
    { label: '!=', value: '!=' },
    { label: '>', value: '>' },
    { label: '<', value: '<' },
    { label: 'like', value: 'like' },
    { label: 'in', value: 'in' },
    { label: 'not_in', value: 'not_in' },
    { label: 'null', value: 'null' },
    { label: 'not_null', value: 'not_null' },
]

const dirOptions = computed(() => [
    { label: t('text.workflow.editor.retrieve.direction.asc'), value: 'asc' },
    { label: t('text.workflow.editor.retrieve.direction.desc'), value: 'desc' },
])

const storeAsValid = computed(() => config.storeAs && config.storeAs.startsWith('vars.'))

const emitConfig = () => {
    if (isHydrating.value) return
    emit('update:modelValue', {
        model: config.model,
        sourceType: config.sourceType,
        relation: config.relation,
        where: config.where.map(({ __id, ...rest }) => rest),
        orderBy: config.orderBy.map(({ __id, ...rest }) => rest),
        first: config.first,
        limit: config.limit,
        storeAs: config.storeAs,
        includeTrashed: config.includeTrashed,
    })
}

const addWhere = () => {
    config.where.push({
        __id: nanoid(),
        field: '',
        op: '==',
        valueExpr: '',
    })
    emitConfig()
}

const removeWhere = (idx) => {
    config.where.splice(idx, 1)
    emitConfig()
}

const addOrder = () => {
    config.orderBy.push({
        __id: nanoid(),
        field: '',
        dir: 'asc',
    })
    emitConfig()
}

const removeOrder = (idx) => {
    config.orderBy.splice(idx, 1)
    emitConfig()
}

const schema = reactive({ fields: [] })

const loadSchema = async () => {
    if (!config.model) {
        schema.fields = []
        return
    }
    try {
        const res = await fetchModelSchema(config.model)
        schema.fields = res?.fields || []
    } catch (error) {
        schema.fields = []
    }
}

watch(
    () => config.model,
    async (model) => {
        config.where = []
        config.orderBy = []
        await loadSchema()
        emitConfig()
    },
    { immediate: true }
)

const useCurrentModel = () => {
    if (!currentModelClass.value) return
    config.model = currentModelClass.value
    emitConfig()
}

const onSourceTypeChange = (val) => {
    config.sourceType = val
    if (val === 'relation') {
        config.model = ''
        config.relation = ''
        schema.fields = []
    } else {
        config.relation = ''
    }
    emitConfig()
}

const onRelationSelect = async (relName) => {
    config.relation = relName || ''
    const rel = rootRelations.value.find((r) => r.name === relName)
    config.model = rel?.model || ''
    await loadSchema()
    emitConfig()
}

onMounted(async () => {
    try {
        const list = await fetchModelList()
        modelOptions.value = (list || []).map((item) => ({
            label: item.short_name ? `${item.short_name} (${item.class})` : item.class,
            value: item.class,
        }))
    } catch (error) {
        modelOptions.value = []
    }
    try {
        if (currentModelClass.value) {
            rootSchema.value = await fetchModelSchema(currentModelClass.value)
        }
    } catch (error) {
        rootSchema.value = null
    }
})

watch(
    currentModelClass,
    async (val) => {
        if (!val) {
            rootSchema.value = null
            return
        }
        try {
            rootSchema.value = await fetchModelSchema(val)
        } catch (error) {
            rootSchema.value = null
        }
    },
    { immediate: true }
)
</script>

<style scoped>
.retrieve-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
