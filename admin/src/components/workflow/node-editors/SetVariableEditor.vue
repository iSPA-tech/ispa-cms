<template>
    <div class="set-var-editor space-y-3">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.set_variable.assignments') }}</p>
            <div class="flex items-center gap-2">
                <a-button size="small" @click="validateAll" :loading="validating" :disabled="readOnly">{{ t('text.workflow.editor.set_variable.validate_all') }}</a-button>
                <a-button size="small" type="primary" @click="addRow" :disabled="readOnly">{{ t('text.workflow.editor.set_variable.add_variable') }}</a-button>
            </div>
        </div>

        <div class="space-y-2">
            <div
                v-for="(row, index) in rows"
                :key="row.__id"
                class="rounded border border-gray-200 p-3 bg-white flex flex-col gap-2"
            >
                <div class="flex items-center gap-2">
                    <a-input
                        v-model:value="row.key"
                        placeholder="vars.example"
                        class="flex-1"
                        :status="rowInvalid(row) ? 'error' : ''"
                        @change="emitRows"
                        :disabled="readOnly"
                    />
                    <a-button size="small" danger @click="removeRow(index)" :disabled="readOnly">{{ t('text.workflow.editor.remove') }}</a-button>
                </div>
                <div class="flex flex-col gap-1">
                    <ExpressionEditor
                        ref="exprRefs"
                        :label="t('text.workflow.editor.set_variable.value_expr')"
                        v-model:model-value="row.valueExpr"
                        :placeholder="'root.id'"
                        :model-class="rootModel"
                        :disabled="readOnly"
                    />
                    <div class="flex items-center gap-2 text-xs">
                        <span v-if="row.status === 'ok'" class="text-green-600">{{ t('text.workflow.editor.valid') }}</span>
                        <span v-else-if="row.status === 'error'" class="text-red-600">
                            {{ t('text.workflow.editor.set_variable.invalid_with_message', { message: row.errors?.[0]?.message || t('mgs.title.error') }) }}
                        </span>
                        <a-button size="small" type="link" @click="promptInsert(index)" :disabled="readOnly">{{ t('text.workflow.editor.set_variable.insert_var') }}</a-button>
                    </div>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span v-if="rowInvalid(row)" class="text-red-600">{{ t('text.workflow.editor.set_variable.key_must_start') }}</span>
                    <span v-else class="text-green-600">{{ t('text.workflow.editor.set_variable.key_ok') }}</span>
                    <span class="text-gray-400">{{ t('text.workflow.editor.set_variable.row', { number: index + 1 }) }}</span>
                </div>
            </div>
            <div v-if="!rows.length" class="text-xs text-gray-500">{{ t('text.workflow.editor.set_variable.add_at_least_one') }}</div>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { nanoid } from 'nanoid'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'
import { validateExpression } from '@/services/workflowApi'

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

const rows = reactive(
    (props.modelValue?.assignments || []).map((item) => ({
        ...item,
        __id: nanoid(),
        status: 'idle',
        errors: [],
    }))
)

watch(
    () => props.modelValue,
    (val) => {
        const list = (val?.assignments || []).map((item) => ({
            ...item,
            __id: nanoid(),
            status: 'idle',
            errors: [],
        }))
        rows.splice(0, rows.length, ...list)
    },
    { deep: true }
)

const rowInvalid = (row) => !row?.key || typeof row.key !== 'string' || !row.key.startsWith('vars.')

const emitRows = () => {
    const assignments = rows
        .filter((r) => typeof r.key === 'string' && r.key.startsWith('vars.') && typeof r.valueExpr === 'string')
        .map(({ __id, status, errors, ...rest }) => rest)
    emit('update:modelValue', { assignments })
}

const addRow = () => {
    rows.push({
        __id: nanoid(),
        key: 'vars.',
        valueExpr: '',
        status: 'idle',
        errors: [],
    })
    emitRows()
}

const removeRow = (index) => {
    rows.splice(index, 1)
    emitRows()
}

const exprRefs = ref([])
const promptInsert = (index) => {
    const row = rows[index]
    if (!row) return
    const selected = prompt(t('text.workflow.editor.set_variable.prompt_insert'))
    if (!selected) return
    const refComp = exprRefs.value?.[index]
    if (refComp?.insertAtCursor) {
        refComp.insertAtCursor(selected)
    } else {
        row.valueExpr = row.valueExpr ? `${row.valueExpr} ${selected}` : selected
    }
    emitRows()
}

const validating = ref(false)
const validateAll = async () => {
    validating.value = true
    try {
        for (const row of rows) {
            if (!row.valueExpr) {
                row.status = 'error'
                row.errors = [{ message: t('text.workflow.editor.expression.required') }]
                continue
            }
            try {
                const res = await validateExpression(row.valueExpr, { modelClass: props.rootModel })
                const ok = res?.ok ?? false
                const errs = res?.errors || []
                row.errors = errs
                row.status = ok && (!errs || errs.length === 0) ? 'ok' : 'error'
            } catch (error) {
                row.status = 'error'
                row.errors = [{ message: error?.message || t('text.workflow.editor.validation_failed') }]
            }
        }
    } finally {
        validating.value = false
    }
}
</script>

<style scoped>
.set-var-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
