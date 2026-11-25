<template>
    <div class="space-y-2" data-testid="editable-fields-picker">
        <p v-if="source.group" class="mb-0 break-words text-xs text-gray-600" data-testid="editable-fields-source">
            {{ t('text.workflow.pick.editable_from', { name: source.group.title }) }}
        </p>
        <p v-else class="mb-0 break-words text-xs text-amber-700" data-testid="editable-fields-no-source">
            {{ t('text.workflow.pick.editable_no_source') }}
        </p>

        <ul class="m-0 list-none space-y-1 p-0">
            <li v-for="row in rows" :key="row.key" :data-field-key="row.key">
                <a-checkbox :checked="chosen.includes(row.key)" :disabled="readOnly" class="!flex items-start" @update:checked="() => toggle(row.key)">
                    <span class="break-words text-sm text-gray-900">{{ row.label }}</span>
                    <span v-if="row.sample" class="block break-words text-xs text-gray-600">{{ row.sample }}</span>
                </a-checkbox>
            </li>
        </ul>
        <p v-if="!rows.length" class="mb-0 text-xs text-gray-600" data-testid="editable-fields-empty">{{ t('text.workflow.pick.editable_none') }}</p>

        <div v-if="adding" class="flex items-center gap-2">
            <a-input
                v-model:value="ownName"
                size="small"
                class="min-w-0 flex-1"
                :placeholder="t('text.workflow.pick.own_field_placeholder')"
                :aria-label="t('text.workflow.pick.own_field')"
                data-testid="editable-fields-own-input"
                @press-enter="addOwn"
            />
            <a-button size="small" type="primary" :disabled="!ownName.trim()" data-testid="editable-fields-own-add" @click="addOwn">{{ t('text.workflow.pick.add') }}</a-button>
        </div>
        <a-button v-else-if="!readOnly" type="link" size="small" class="!h-auto !p-0" data-testid="editable-fields-own" @click="adding = true">
            + {{ t('text.workflow.pick.own_field') }}
        </a-button>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { humanize } from '@/components/workflow/inputs/dataTokens.js'
import { useStepData, useStepForm } from '@/components/workflow/inputs/useStepData.js'
import { editableCandidates } from './widgetRules.js'

/**
 * What a reviewer may edit before approving: a checklist of the text parts of the step whose result is reviewed.
 * Stored as the list of part names (`editable_fields`). A name the list does not have (set by the sample, or added
 * as an own field) stays ticked.
 */
defineOptions({ name: 'EditableFieldsPicker' })

const props = defineProps({
    modelValue: { type: null, default: () => [] },
    readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const stepData = useStepData()
const form = useStepForm()

const chosen = computed(() => (Array.isArray(props.modelValue) ? props.modelValue.filter((key) => typeof key === 'string' && key !== '') : []))
const source = computed(() => editableCandidates(form?.config.value?.review, stepData.groups.value))

const rows = computed(() => {
    const list = source.value.items.map((item) => ({ key: item.key, label: item.label, sample: item.sample }))
    chosen.value.forEach((key) => {
        if (!list.some((row) => row.key === key)) list.push({ key, label: humanize(key.split('.').pop()), sample: '' })
    })
    return list
})

const toggle = (key) => {
    if (props.readOnly) return
    emit('update:modelValue', chosen.value.includes(key) ? chosen.value.filter((item) => item !== key) : [...chosen.value, key])
}

const adding = ref(false)
const ownName = ref('')
const addOwn = () => {
    const key = ownName.value.trim()
    if (!key) return
    if (!chosen.value.includes(key)) emit('update:modelValue', [...chosen.value, key])
    ownName.value = ''
    adding.value = false
}
</script>
