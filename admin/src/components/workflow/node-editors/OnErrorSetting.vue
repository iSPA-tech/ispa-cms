<template>
    <fieldset class="m-0 min-w-0 space-y-2 border-0 border-t border-solid border-gray-200 p-0 pt-3" data-testid="on-error-setting">
        <legend class="float-left !mb-2 w-full !border-0 p-0 !text-sm font-semibold !text-gray-700">{{ t('text.workflow.on_error.title') }}</legend>
        <a-radio-group :value="shownMode" :disabled="disabled" class="!flex w-full flex-col gap-2" @update:value="choose">
            <a-radio v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled" class="!items-start" :data-option="option.value">
                <span class="block break-words text-sm text-gray-900">{{ t(`text.workflow.on_error.${option.value}`) }}</span>
                <span class="block break-words text-xs text-gray-600">{{ option.hint }}</span>
            </a-radio>
        </a-radio-group>
    </fieldset>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ON_ERROR_OWN, ON_ERROR_SKIP, ON_ERROR_STOP } from './onErrorRule.js'

/**
 * "When this step fails": stop, carry on with the next step, or follow the step's own error path. The choice is read
 * from the diagram's links and changes links (never the step settings); the designer applies it.
 */
defineOptions({ name: 'OnErrorSetting' })

const props = defineProps({
    /** The choice the links stand for now: stop | skip | own. */
    mode: { type: String, default: ON_ERROR_STOP },
    /** Whether the step has a next step to carry on with. */
    canSkip: { type: Boolean, default: false },
    /** Name of the step the own error path leads to, '' when it is not connected yet. */
    ownTarget: { type: String, default: '' },
    /** Key of the step being edited: the pending "own path" choice belongs to that step only. */
    stepKey: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['change'])

const { t } = useI18n()

// "Own error path" before any link is drawn looks like "stop" in the diagram: the choice is remembered here until the
// operator connects the error port (or picks something else).
const wantsOwn = ref(false)
watch(
    () => [props.stepKey, props.mode],
    ([key, mode], [oldKey] = []) => {
        if (key !== oldKey || mode !== ON_ERROR_STOP) wantsOwn.value = false
    }
)

const shownMode = computed(() => (props.mode === ON_ERROR_STOP && wantsOwn.value ? ON_ERROR_OWN : props.mode))

const options = computed(() => [
    { value: ON_ERROR_STOP, hint: t('text.workflow.on_error.stop_hint') },
    {
        value: ON_ERROR_SKIP,
        disabled: !props.canSkip,
        hint: props.canSkip ? t('text.workflow.on_error.skip_hint') : t('text.workflow.on_error.skip_unavailable'),
    },
    {
        value: ON_ERROR_OWN,
        hint: props.ownTarget ? t('text.workflow.on_error.own_connected', { step: props.ownTarget }) : t('text.workflow.on_error.own_hint'),
    },
])

const choose = (value) => {
    if (props.disabled || value === shownMode.value) return
    wantsOwn.value = value === ON_ERROR_OWN
    emit('change', value)
}
</script>
