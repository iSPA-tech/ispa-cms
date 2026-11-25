<template>
    <div class="space-y-3" data-testid="decision-editor">
        <!-- A condition typed in an older workflow: kept as it is until the operator builds it again from the lists. -->
        <template v-if="typed && !building">
            <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.decision.condition') }}</p>
            <ExpressionEditor :model-value="typedText" :model-class="rootModel" :disabled="readOnly" @update:model-value="setTyped" />
            <a-button v-if="!readOnly" type="link" size="small" class="!h-auto !p-0" data-testid="decision-build" @click="startBuilding">
                {{ t('text.workflow.cond.build') }}
            </a-button>
        </template>

        <template v-else>
            <div v-if="questions.length && !readOnly" class="space-y-1" data-testid="decision-questions">
                <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.cond.common') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    <a-button
                        v-for="question in questions"
                        :key="question.key"
                        size="small"
                        class="!h-auto !whitespace-normal !break-words !text-left"
                        :type="isAsked(question) ? 'primary' : 'default'"
                        :ghost="isAsked(question)"
                        :aria-pressed="isAsked(question) ? 'true' : 'false'"
                        :data-question="question.key"
                        @click="ask(question)"
                    >
                        {{ questionText(question) }}
                    </a-button>
                </div>
            </div>

            <div class="space-y-2">
                <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.cond.own') }}</p>
                <a-select
                    v-if="rows.length > 1"
                    class="w-full"
                    :value="combinator"
                    :options="combinatorOptions"
                    :disabled="readOnly"
                    :aria-label="t('text.workflow.cond.combinator')"
                    data-testid="decision-combinator"
                    @update:value="setCombinator"
                />
                <div v-for="(row, index) in rows" :key="index" class="space-y-1 rounded-md border border-solid border-slate-200 p-2" data-testid="decision-row">
                    <ExpressionEditor
                        variant="pick"
                        :model-value="row.left"
                        :model-class="rootModel"
                        :disabled="readOnly"
                        @update:model-value="(left) => setLeft(index, left)"
                    />
                    <div class="flex flex-wrap items-center gap-1">
                        <a-select
                            class="min-w-[7rem] flex-1"
                            :value="row.operator || 'eq'"
                            :options="operatorOptions(row)"
                            :disabled="readOnly"
                            :aria-label="t('text.workflow.cond.operator')"
                            data-testid="decision-operator"
                            @update:value="(operator) => setRow(index, { operator })"
                        />
                        <template v-if="!isUnary(row)">
                            <a-select
                                v-if="valuesOf(row).length"
                                class="min-w-[7rem] flex-1"
                                :value="row.right ?? undefined"
                                :options="valueOptions(row)"
                                :disabled="readOnly"
                                :placeholder="t('text.workflow.cond.value_pick')"
                                :aria-label="t('text.workflow.cond.value')"
                                data-testid="decision-value-select"
                                @update:value="(right) => setRow(index, { right, rightExpr: undefined })"
                            />
                            <span
                                v-else-if="row.rightExpr"
                                class="min-w-0 flex-1 break-all rounded-md border border-solid border-slate-300 bg-slate-100 px-2 font-mono text-xs leading-6 text-slate-700"
                                :title="t('text.workflow.data.formula_note')"
                                data-testid="decision-value-formula"
                            >
                                {{ row.rightExpr }}
                            </span>
                            <a-input-number
                                v-else-if="kindOf(row) === 'number'"
                                class="min-w-[6rem] flex-1"
                                :value="typeof row.right === 'number' ? row.right : row.right === '' || row.right == null ? null : Number(row.right)"
                                :disabled="readOnly"
                                :aria-label="t('text.workflow.cond.value')"
                                data-testid="decision-value-number"
                                @update:value="(right) => setRow(index, { right: right ?? '' })"
                            />
                            <a-input
                                v-else
                                class="min-w-[6rem] flex-1"
                                :value="row.right == null ? '' : String(row.right)"
                                :disabled="readOnly"
                                :placeholder="t('text.workflow.cond.value')"
                                :aria-label="t('text.workflow.cond.value')"
                                data-testid="decision-value-text"
                                @update:value="(right) => setRow(index, { right })"
                            />
                        </template>
                        <a-button
                            v-if="!readOnly && rows.length > 1"
                            size="small"
                            type="text"
                            danger
                            :aria-label="t('text.workflow.schema_form.remove_row')"
                            :title="t('text.workflow.schema_form.remove_row')"
                            data-testid="decision-row-remove"
                            @click="removeRow(index)"
                        >
                            <i-icon icon="trash-can-outline" class="h-4 w-4" />
                        </a-button>
                    </div>
                </div>
                <a-button v-if="!readOnly" size="small" type="dashed" block data-testid="decision-row-add" @click="addRow">+ {{ t('text.workflow.cond.add') }}</a-button>
            </div>

            <p
                v-if="summary"
                class="mb-0 break-words rounded-lg border border-solid border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900"
                data-testid="decision-summary"
            >
                {{ t('text.workflow.cond.read_back', { text: summary }) }}
            </p>
            <p v-else class="mb-0 break-words text-xs text-amber-700" data-testid="decision-incomplete">{{ t('text.workflow.cond.incomplete') }}</p>
        </template>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'
import { findDataItem, readPath } from '@/components/workflow/inputs/dataTokens.js'
import { useStepData } from '@/components/workflow/inputs/useStepData.js'
import {
    OPERATORS_BY_KIND,
    UNARY_OPERATORS,
    commonQuestions,
    conditionKind,
    isTypedCondition,
    readConditions,
    writeConditions,
} from '@/components/workflow/widgets/widgetRules.js'

/**
 * The question of a yes / no step, built from lists: which data, how it is compared, and the value (picked from the
 * values the data may hold when they are known). Stored as the engine reads it (`conditions` rows joined by
 * `combinator`). Ready questions come from the data of the steps before this one.
 * A condition typed in an older workflow (`expression`) is shown and kept as it is until the operator rebuilds it.
 * Every other setting of the step is kept untouched.
 */
defineOptions({ name: 'DecisionEditor' })

const props = defineProps({
    step: { type: Object, default: () => ({}) },
    stepType: { type: String, default: '' },
    modelValue: { type: Object, default: () => ({}) },
    rootModel: { type: String, default: '' },
    steps: { type: Array, default: () => [] },
    readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { t, te } = useI18n()
const stepData = useStepData()

const config = computed(() => (props.modelValue && typeof props.modelValue === 'object' ? props.modelValue : {}))
const typed = computed(() => isTypedCondition(config.value))
const typedText = computed(() => String(config.value.expression ?? config.value.condition ?? ''))
const building = ref(false)

// The rows on screen. They follow the stored rows, except for a row still being filled in (not stored yet).
const rows = ref([])
const combinator = ref('and')
let written = null
watch(
    config,
    (value) => {
        const stored = JSON.stringify([value.conditions ?? null, value.combinator ?? null])
        if (stored === written) return
        written = stored
        const list = readConditions(value)
        rows.value = list.length ? list : [{ left: '', operator: 'eq', right: '' }]
        combinator.value = String(value.combinator || 'and').toLowerCase() === 'or' ? 'or' : 'and'
    },
    { immediate: true, deep: true }
)
watch(
    () => props.step?.id,
    () => (building.value = false)
)

const complete = (row) => typeof row.left === 'string' && row.left.trim() !== ''
const write = () => {
    if (props.readOnly) return
    const ready = rows.value.filter(complete)
    // Nothing to store yet: the typed condition (or the default) stays until one row names its data.
    if (!ready.length) return
    const next = { ...config.value }
    Object.entries(writeConditions(ready, combinator.value)).forEach(([key, value]) => {
        if (value === undefined) delete next[key]
        else next[key] = value
    })
    written = JSON.stringify([next.conditions ?? null, next.combinator ?? null])
    emit('update:modelValue', next)
}

const setTyped = (expression) => !props.readOnly && emit('update:modelValue', { ...config.value, expression })
const startBuilding = () => {
    building.value = true
    rows.value = [{ left: '', operator: 'eq', right: '' }]
}

const itemOf = (row) => {
    const view = readPath(row.left)
    return view.kind === 'chip' ? findDataItem(view.path, stepData.groups.value)?.item || null : null
}
const kindOf = (row) => conditionKind(itemOf(row))
const valuesOf = (row) => itemOf(row)?.values || []
const isUnary = (row) => UNARY_OPERATORS.includes(row.operator)

const operatorOptions = (row) => {
    const list = [...OPERATORS_BY_KIND[kindOf(row)]]
    // A comparison stored by hand stays selectable.
    if (row.operator && !list.includes(row.operator)) list.push(row.operator)
    return list.map((value) => ({ value, label: t(`text.workflow.cond.op_${value}`) }))
}
const valueOptions = (row) => {
    const options = valuesOf(row).map((value) => ({ value: value.value, label: value.label }))
    if (row.right !== undefined && row.right !== null && row.right !== '' && !options.some((option) => option.value === row.right)) {
        options.push({ value: row.right, label: String(row.right) })
    }
    return options
}

const setRow = (index, patch) => {
    rows.value = rows.value.map((row, i) => (i === index ? { ...row, ...patch } : row))
    write()
}
/** Other data: the comparison and the value are kept only when they still fit it. */
const setLeft = (index, left) => {
    const row = { ...rows.value[index], left }
    const allowed = OPERATORS_BY_KIND[kindOf(row)]
    if (!allowed.includes(row.operator)) row.operator = allowed[0]
    const values = valuesOf(row)
    if (values.length && !values.some((value) => value.value === row.right)) row.right = ''
    rows.value = rows.value.map((old, i) => (i === index ? row : old))
    write()
}
const removeRow = (index) => {
    rows.value = rows.value.filter((_, i) => i !== index)
    write()
}
const addRow = () => (rows.value = [...rows.value, { left: '', operator: 'eq', right: '' }])
const setCombinator = (value) => {
    combinator.value = value
    write()
}
const combinatorOptions = computed(() => ['and', 'or'].map((value) => ({ value, label: t(`text.workflow.cond.combinator_${value}`) })))

// Ready questions
const questions = computed(() => commonQuestions(stepData.groups.value))
const questionText = (question) => {
    const name = `${question.group.title} › ${question.item.label}`
    return question.value ? t('text.workflow.cond.question_is', { name, value: question.value.label }) : t('text.workflow.cond.question_yes', { name })
}
const sameRow = (row, question) => row.left === question.left && (row.operator || 'eq') === question.operator && (question.right === undefined || row.right === question.right)
const isAsked = (question) => rows.value.length === 1 && sameRow(rows.value[0], question)
/** A ready question replaces a single row; with several rows it is added to them. */
const ask = (question) => {
    const row = { left: question.left, operator: question.operator, ...(question.right !== undefined ? { right: question.right } : {}) }
    rows.value = rows.value.filter(complete).length > 1 ? [...rows.value.filter(complete), row] : [row]
    building.value = true
    write()
}

/** The condition in a sentence, so the operator can read back what "Yes" means. */
const summary = computed(() => {
    const ready = rows.value.filter(complete)
    if (!ready.length || ready.length !== rows.value.length) return ''
    const parts = ready.map((row) => {
        const view = readPath(row.left)
        const name = view.kind === 'chip' ? stepData.labelFor(view.path) : row.left
        const operator = t(`text.workflow.cond.op_${row.operator || 'eq'}`)
        if (isUnary(row)) return `${name} ${operator}`
        const value = row.rightExpr || valuesOf(row).find((item) => item.value === row.right)?.label || row.right
        if (value === undefined || value === null || value === '') return ''
        return `${name} ${operator} “${value}”`
    })
    if (parts.some((part) => !part)) return ''
    const joiner = te(`text.workflow.cond.join_${combinator.value}`) ? t(`text.workflow.cond.join_${combinator.value}`) : combinator.value
    return parts.join(` ${joiner} `)
})
</script>
