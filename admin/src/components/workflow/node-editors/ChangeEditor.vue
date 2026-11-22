<template>
    <div class="change-editor space-y-3">
        <div class="space-y-1">
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.target_expr') }}</p>
            <ExpressionEditor
                v-model:model-value="config.targetExpr"
                :placeholder="'vars.customer'"
                :model-class="rootModel"
                :disabled="readOnly"
            />
            <p class="text-xs text-gray-500">{{ t('text.workflow.editor.change.target_expr_hint') }}</p>
        </div>

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.change.changes') }}</p>
                <a-button size="small" @click="addChange" :disabled="readOnly">{{ t('text.workflow.editor.change.add_change') }}</a-button>
            </div>
            <div v-if="!config.changes.length" class="text-xs text-gray-500">{{ t('text.workflow.editor.change.no_changes_yet') }}</div>
            <div
                v-for="(c, idx) in config.changes"
                :key="c.__id"
                class="rounded border border-gray-200 p-3 bg-gray-50 space-y-2"
            >
                <div class="grid grid-cols-1 md:grid-cols-6 gap-2 items-center">
                    <a-select
                        v-if="fieldOptions.length"
                        v-model:value="c.field"
                        :options="fieldOptions"
                        show-search
                        :placeholder="t('text.workflow.editor.create_object.field_placeholder')"
                        class="md:col-span-2"
                        @change="emitConfig"
                        :disabled="readOnly"
                    />
                    <a-input
                        v-else
                        v-model:value="c.field"
                        :placeholder="t('text.workflow.editor.create_object.field_name_placeholder')"
                        class="md:col-span-2"
                        @change="emitConfig"
                        :disabled="readOnly"
                    />
                    <ExpressionEditor
                        class="md:col-span-4"
                        v-model:model-value="c.valueExpr"
                        :placeholder="'true'"
                        :model-class="rootModel"
                        :disabled="readOnly"
                    />
                </div>
                <div class="md:col-span-6 flex justify-end">
                    <a-button size="small" danger @click="removeChange(idx)" :disabled="readOnly"
                        >{{ t('text.workflow.editor.remove') }}</a-button
                    >
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
    rootModel: {
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
    targetExpr: 'vars.customer',
    changes: [],
    save: true,
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            targetExpr: val?.targetExpr || 'vars.customer',
            changes: (val?.changes || []).map((c) => ({ __id: nanoid(), ...c })),
            save: val?.save ?? true,
        })
    },
    { immediate: true, deep: true }
)

const emitConfig = () => {
    emit('update:modelValue', {
        targetExpr: config.targetExpr,
        changes: config.changes.map(({ __id, ...rest }) => rest),
        save: config.save,
    })
}

const addChange = () => {
    config.changes.push({
        __id: nanoid(),
        field: '',
        valueExpr: '',
    })
    emitConfig()
}

const removeChange = (idx) => {
    config.changes.splice(idx, 1)
    emitConfig()
}

const schema = reactive({ fields: [] })
const fieldOptions = computed(() =>
    (schema.fields || []).map((f) => ({ label: f.name, value: f.name }))
)

const loadSchema = async () => {
    const model = props.rootModel
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
    () => props.rootModel,
    () => {
        loadSchema()
    },
    { immediate: true }
)
</script>

<style scoped>
.change-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
