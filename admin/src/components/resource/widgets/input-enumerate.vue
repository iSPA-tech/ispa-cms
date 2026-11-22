<script setup>
/**
 * Enumerate input widget: an a-select over the entries of one enumerates group (`field.enumerate.group`). Options load
 * from `field.enumerate.endpoint` (filtered by `type`) when the widget mounts and show the entry name in the current
 * language (name, or name_alias outside Vietnamese); the submitted value is the entry key (`value_key`). Shows loading,
 * empty and error (with retry) states; the request is aborted on unmount and stale responses are ignored.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'
import { useAppRuntime } from '@/runtime'
import { toAjaxPath } from '@/utils/resource/endpoints'

const props = defineProps({
    modelValue: { type: [String, Number], default: undefined },
    field: { type: Object, default: () => ({}) },
    record: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()
const { locale } = useAppRuntime()

const config = computed(() => props.field?.enumerate || {})
const valueKey = computed(() => config.value.value_key || 'enum_key')

const items = ref([])
const loading = ref(false)
const failed = ref(false)

let controller = null
let sequence = 0

/** Entry name in the current language. */
function labelOf(item) {
    const name = item[config.value.label_key || 'name']
    const alias = item[config.value.alias_key || 'name_alias']
    return String(locale.value === 'vi' ? name || alias || '' : alias || name || '')
}

const options = computed(() => items.value.map((item) => ({ value: item[valueKey.value], label: labelOf(item) })))

/** Load the group's entries; a response older than the latest request is ignored. */
async function load() {
    sequence += 1
    const current = sequence
    controller?.abort()
    controller = new AbortController()
    loading.value = true
    failed.value = false
    try {
        const res = await ajax.get(toAjaxPath(config.value.endpoint), {
            params: { type: config.value.group },
            signal: controller.signal,
            silent: true,
        })
        if (current !== sequence) return
        const rows = res?.data?.data
        items.value = Array.isArray(rows) ? rows : []
    } catch {
        if (current !== sequence) return
        failed.value = true
        items.value = []
    }
    loading.value = false
}

onMounted(load)
onBeforeUnmount(() => {
    sequence += 1
    controller?.abort()
    controller = null
})
</script>

<template>
    <a-select
        :value="modelValue"
        :options="options"
        :disabled="disabled"
        :loading="loading"
        option-filter-prop="label"
        show-search
        allow-clear
        style="width: 100%"
        @update:value="emit('update:modelValue', $event ?? null)"
    >
        <template #notFoundContent>
            <div v-if="loading" class="r-relation-state r-relation-loading"><a-spin size="small" /></div>
            <div v-else-if="failed" class="r-relation-state r-relation-error">
                <span>{{ t('text.resource.load_failed') }}</span>
                <i-button size="sm" @click="load">{{ t('text.resource.retry') }}</i-button>
            </div>
            <div v-else class="r-relation-state r-relation-empty">{{ t('text.resource.empty') }}</div>
        </template>
    </a-select>
</template>
