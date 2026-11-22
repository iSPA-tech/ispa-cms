<template>
    <div class="create-object-editor space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-start">
            <div class="space-y-1">
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.create_object.model') }}</p>
                <a-select
                    show-search
                    allow-clear
                    v-model:value="config.model"
                    :options="modelOptions"
                    :placeholder="t('text.workflow.editor.create_object.select_or_type_model')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
                <a-input
                    v-model:value="config.model"
                    :placeholder="t('text.workflow.editor.create_object.model_example')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
                <div class="flex items-center gap-2 text-xs">
                    <a-button size="small" type="link" @click="useCurrentModel" :disabled="readOnly || !currentModelClass">
                        {{
                            t('text.workflow.editor.use_current_model', {
                                value: currentModelClass || t('text.workflow.editor.no_current_model'),
                            })
                        }}
                    </a-button>
                </div>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.store_as') }}</p>
                <a-input
                    v-model:value="config.storeAs"
                    :placeholder="t('text.workflow.editor.create_object.store_as_placeholder')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
                <p class="text-xs" :class="storeAsValid ? 'text-green-600' : 'text-red-600'">
                    {{ t('text.workflow.editor.store_as_hint_required') }}
                </p>
            </div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.create_object.fields') }}</p>
                <a-button size="small" @click="addField" :disabled="readOnly">
                    {{ t('text.workflow.editor.create_object.add_field') }}
                </a-button>
            </div>
            <div v-if="!config.fields.length" class="text-xs text-gray-500">
                {{ t('text.workflow.editor.create_object.no_fields_yet') }}
            </div>
            <div
                v-for="(f, idx) in config.fields"
                :key="f.__id"
                class="rounded border border-gray-200 p-3 bg-gray-50 space-y-2"
            >
                <div class="grid grid-cols-1 md:grid-cols-6 gap-2 items-center">
                    <a-select
                        v-if="fieldOptions.length"
                        v-model:value="f.field"
                        :options="fieldOptions"
                        show-search
                        :placeholder="t('text.workflow.editor.create_object.field_placeholder')"
                        class="md:col-span-2"
                        @change="emitConfig"
                        :disabled="readOnly"
                    />
                    <a-input
                        v-else
                        v-model:value="f.field"
                        :placeholder="t('text.workflow.editor.create_object.field_name_placeholder')"
                        class="md:col-span-2"
                        @change="emitConfig"
                        :disabled="readOnly"
                    />
                    <ExpressionEditor
                        class="md:col-span-4"
                        v-model:model-value="f.valueExpr"
                        :placeholder="'root.id'"
                        :model-class="config.model || currentModelClass"
                        :disabled="readOnly"
                    />
                </div>
                <div class="md:col-span-6 flex justify-end">
                    <a-button size="small" danger @click="removeField(idx)" :disabled="readOnly">
                        {{ t('text.workflow.editor.remove') }}
                    </a-button>
                </div>
            </div>
        </div>

        <a-checkbox v-model:checked="config.save" @change="emitConfig" :disabled="readOnly">
            {{ t('text.workflow.editor.create_object.save_immediately') }}
        </a-checkbox>
    </div>
</template>

<script setup>
import { nanoid } from 'nanoid'
import { reactive, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'
import { fetchModelSchema } from '@/services/workflowApi'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    modelClass: {
        type: String,
        default: '',
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const config = reactive({
    model: '',
    fields: [],
    save: true,
    storeAs: 'vars.result',
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            model: val?.model || '',
            fields: (val?.fields || []).map((f) => ({ __id: nanoid(), ...f })),
            save: val?.save ?? true,
            storeAs: val?.storeAs || 'vars.result',
        })
    },
    { immediate: true, deep: true }
)

const currentModelClass = computed(() => props.modelClass || '')

const modelOptions = computed(() => {
    const opts = []
    if (config.model) opts.push({ label: config.model, value: config.model })
    if (currentModelClass.value && currentModelClass.value !== config.model) {
        opts.push({ label: currentModelClass.value, value: currentModelClass.value })
    }
    return opts
})

const storeAsValid = computed(() => config.storeAs && config.storeAs.startsWith('vars.'))

const emitConfig = () => {
    emit('update:modelValue', {
        model: config.model,
        fields: config.fields.map(({ __id, ...rest }) => rest),
        save: config.save,
        storeAs: config.storeAs,
    })
}

const addField = () => {
    config.fields.push({
        __id: nanoid(),
        field: '',
        valueExpr: '',
    })
    emitConfig()
}

const removeField = (idx) => {
    config.fields.splice(idx, 1)
    emitConfig()
}

const schema = reactive({ fields: [] })
const fieldOptions = computed(() => (schema.fields || []).map((f) => ({ label: f.name, value: f.name })))

const loadSchema = async () => {
    const model = config.model || currentModelClass.value
    if (!model) {
        schema.fields = []
        return
    }
    try {
        const res = await fetchModelSchema(model)
        schema.fields = res?.fields || []
    } catch (error) {
        schema.fields = []
    }
}

watch(
    () => config.model,
    async () => {
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
</script>

<style scoped>
.create-object-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
