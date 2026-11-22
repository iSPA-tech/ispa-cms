<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <p class="mb-0 text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.clear_variable.targets') }}</p>
            <div class="flex items-center gap-2">
                <a-tag
                    v-for="chip in quickAdds"
                    :key="chip"
                    color="blue"
                    class="cursor-pointer"
                    @click="addTarget(chip)"
                >
                    {{ chip }}
                </a-tag>
                <a-button size="small" @click="addTarget()">{{ t('nav.b.add') }}</a-button>
            </div>
        </div>
        <p class="text-xs text-gray-500 mb-2">{{ t('text.workflow.editor.clear_variable.only_vars') }}</p>

        <div class="space-y-2">
            <div v-for="(target, idx) in targets" :key="idx" class="flex items-center gap-2">
                <a-input
                    v-model:value="targets[idx]"
                    :status="isInvalid(target) ? 'error' : ''"
                    placeholder="vars.customer"
                    :disabled="readOnly"
                    @change="emitChange"
                />
                <a-button
                    size="small"
                    danger
                    type="text"
                    :disabled="readOnly"
                    @click="removeTarget(idx)"
                >
                    {{ t('text.workflow.editor.remove') }}
                </a-button>
            </div>
            <div v-if="!targets.length" class="text-xs text-gray-500">
                {{ t('text.workflow.editor.clear_variable.no_targets') }}
            </div>
        </div>

        <div v-if="invalidAny" class="text-xs text-amber-600 flex items-center gap-1">
            <span>⚠</span>
            <span>{{ t('text.workflow.editor.clear_variable.paths_must_start') }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const targets = ref([])

const quickAdds = ['vars.data', 'vars.tempData']

watch(
    () => props.modelValue,
    (val) => {
        targets.value = Array.isArray(val?.targets) ? [...val.targets] : []
    },
    { immediate: true, deep: true }
)

const isInvalid = (value) => !value || typeof value !== 'string' || !value.startsWith('vars.')
const invalidAny = computed(() => targets.value.some((t) => isInvalid(t)))

const emitChange = () => {
    const filtered = targets.value.filter((t) => typeof t === 'string' && t.startsWith('vars.'))
    emit('update:modelValue', { targets: filtered })
}

const addTarget = (value) => {
    if (props.readOnly) return
    targets.value.push(value || 'vars.')
    emitChange()
}

const removeTarget = (idx) => {
    if (props.readOnly) return
    targets.value.splice(idx, 1)
    emitChange()
}
</script>
