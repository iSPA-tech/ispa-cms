<template>
    <div class="log-message-editor space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
            <div class="md:col-span-1">
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('validation.attributes.level') }}</p>
                <a-select
                    v-model:value="config.level"
                    :options="levelOptions"
                    :placeholder="t('text.workflow.editor.log_message.level.info')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
            <div class="md:col-span-2 space-y-1">
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.message') }}</p>
                <TemplateEditor
                    v-model:model-value="config.message"
                    :placeholder="'Order {{ root.id }} processed'"
                    :disabled="readOnly"
                />
            </div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.log_message.context') }}</p>
                <a-button size="small" @click="addContext" :disabled="readOnly">{{ t('text.workflow.editor.log_message.add_context') }}</a-button>
            </div>
            <div v-if="!contextRows.length" class="text-xs text-gray-500">{{ t('text.workflow.editor.log_message.no_context') }}</div>
            <div
                v-for="(row, idx) in contextRows"
                :key="row.__id"
                class="rounded border border-gray-200 p-3 bg-gray-50 space-y-2"
            >
                <div class="grid grid-cols-1 md:grid-cols-5 gap-2 items-center">
                    <a-input
                        v-model:value="row.key"
                        placeholder="total"
                        class="md:col-span-2"
                        @change="emitConfig"
                        :disabled="readOnly"
                    />
                    <TemplateEditor
                        class="md:col-span-3"
                        v-model:model-value="row.value"
                        :placeholder="'{{ root.total }}'"
                        :disabled="readOnly"
                    />
                </div>
                <div class="flex justify-end">
                    <a-button size="small" danger @click="removeContext(idx)" :disabled="readOnly">{{ t('text.workflow.editor.remove') }}</a-button>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a-button size="small" @click="preview" :loading="previewing" :disabled="readOnly">
                {{ t('nav.b.preview') }}
            </a-button>
            <span v-if="previewStatus === 'ok'" class="text-xs text-green-600">{{ t('text.workflow.editor.log_message.preview_ready') }}</span>
            <span v-else-if="previewStatus === 'error'" class="text-xs text-red-600">{{ t('text.workflow.editor.preview_failed') }}</span>
        </div>

        <div v-if="previewMessage !== null" class="p-2 rounded border bg-gray-50 text-sm text-gray-800">
            {{ previewMessage }}
        </div>

        <div v-if="previewContext && Object.keys(previewContext).length" class="space-y-1 text-sm">
            <p class="font-semibold text-gray-700 mb-0">{{ t('text.workflow.editor.log_message.context_preview') }}</p>
            <div v-for="(val, key) in previewContext" :key="key" class="flex items-center gap-2">
                <span class="text-gray-500">{{ key }}:</span>
                <span class="text-gray-800">{{ val }}</span>
            </div>
        </div>

        <div v-if="errors.length" class="space-y-1">
            <div v-for="(err, idx) in errors" :key="idx" class="text-xs text-red-600">
                • {{ err }}
            </div>
        </div>
    </div>
</template>

<script setup>
import { nanoid } from 'nanoid'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import TemplateEditor from '@/components/workflow/inputs/TemplateEditor.vue'
import { previewTemplate } from '@/services/workflowApi'

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

const config = reactive({
    level: 'info',
    message: '',
    context: {},
    ...(props.modelValue || {}),
})

const contextRows = ref([])

watch(
    () => props.modelValue,
    (val) => {
        config.level = val?.level || 'info'
        config.message = val?.message || ''
        config.context = val?.context || {}
        contextRows.value = Object.entries(config.context || {}).map(([key, value]) => ({
            __id: nanoid(),
            key,
            value: typeof value === 'string' ? value : JSON.stringify(value),
        }))
    },
    { immediate: true, deep: true }
)

const levelOptions = computed(() => [
    { label: t('text.workflow.editor.log_message.level.info'), value: 'info' },
    { label: t('text.workflow.editor.log_message.level.warning'), value: 'warning' },
    { label: t('mgs.title.error'), value: 'error' },
])

const emitConfig = () => {
    const ctx = {}
    contextRows.value.forEach((row) => {
        if (!row.key) return
        ctx[row.key] = row.value ?? ''
    })
    emit('update:modelValue', {
        level: config.level,
        message: config.message,
        context: ctx,
    })
}

const addContext = () => {
    contextRows.value.push({
        __id: nanoid(),
        key: '',
        value: '',
    })
    emitConfig()
}

const removeContext = (idx) => {
    contextRows.value.splice(idx, 1)
    emitConfig()
}

const previewing = ref(false)
const previewMessage = ref(null)
const previewContext = ref(null)
const previewStatus = ref('idle')
const errors = ref([])

const preview = async () => {
    errors.value = []
    previewStatus.value = 'idle'
    previewMessage.value = null
    previewContext.value = null
    previewing.value = true
    try {
        const msgRes = await previewTemplate(config.message || '')
        const ok = msgRes?.ok ?? false
        const errList = msgRes?.errors || []
        if (!ok && errList?.length) {
            errors.value.push(...errList.map((e) => e.message || t('text.workflow.editor.log_message.message_preview_failed')))
        }
        previewMessage.value = msgRes?.rendered ?? config.message

        const ctxRendered = {}
        for (const row of contextRows.value) {
            if (!row.key) continue
            const res = await previewTemplate(row.value || '')
            const okCtx = res?.ok ?? false
            const errCtx = res?.errors || []
            if (!okCtx && errCtx?.length) {
                errors.value.push(...errCtx.map((e) => `${row.key}: ${e.message || t('text.workflow.editor.preview_failed')}`))
            }
            ctxRendered[row.key] = res?.rendered ?? row.value
        }
        previewContext.value = ctxRendered
        previewStatus.value = errors.value.length ? 'error' : 'ok'
    } catch (error) {
        previewStatus.value = 'error'
        errors.value.push(error?.message || t('text.workflow.editor.preview_failed'))
    } finally {
        previewing.value = false
    }
}

watch(
    () => [config.level, config.message, contextRows.value],
    () => emitConfig(),
    { deep: true }
)
</script>

<style scoped>
.log-message-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
