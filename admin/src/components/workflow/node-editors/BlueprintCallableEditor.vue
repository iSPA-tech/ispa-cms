<template>
    <div class="blueprint-editor space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-start">
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.blueprint.type') }}</p>
                <a-select v-model:value="cfg.callableType" :options="typeOptions" @change="emitConfig" :disabled="readOnly" />
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.blueprint.class') }}</p>
                <a-select
                    show-search
                    v-model:value="cfg.class"
                    :options="classOptions"
                    :placeholder="t('text.workflow.editor.blueprint.select_class')"
                    @change="onClassChange"
                    :disabled="readOnly"
                />
            </div>
            <div v-if="cfg.callableType === 'service'">
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.blueprint.method') }}</p>
                <a-select
                    show-search
                    v-model:value="cfg.method"
                    :options="methodOptions"
                    :placeholder="t('text.workflow.editor.blueprint.select_method')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
        </div>

        <div v-if="cfg.callableType === 'job'" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.blueprint.mode') }}</p>
                <a-select v-model:value="cfg.execution.mode" :options="modeOptions" @change="emitConfig" :disabled="readOnly" />
                <p class="text-xs text-amber-600">{{ t('text.workflow.editor.blueprint.async_warning_short') }}</p>
            </div>
            <div v-if="cfg.execution.mode === 'async'">
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.blueprint.queue') }}</p>
                <a-input
                    v-model:value="cfg.execution.queue"
                    :placeholder="t('text.workflow.editor.blueprint.queue_placeholder')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
            <div v-if="cfg.execution.mode === 'async'">
                <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.blueprint.connection') }}</p>
                <a-input
                    v-model:value="cfg.execution.connection"
                    :placeholder="t('text.workflow.editor.blueprint.connection_placeholder')"
                    @change="emitConfig"
                    :disabled="readOnly"
                />
            </div>
        </div>

        <div>
            <div class="flex items-center justify-between mb-1">
                <p class="text-sm font-semibold text-gray-700">{{ t('text.workflow.editor.blueprint.arguments_mapping') }}</p>
                <a-button size="small" @click="addArg">{{ t('text.workflow.editor.blueprint.add_argument') }}</a-button>
            </div>
            <div v-if="argList.length === 0" class="text-xs text-gray-500">
                {{ t('text.workflow.editor.blueprint.no_arguments_mapped') }}
            </div>
            <div
                v-for="(arg, idx) in argList"
                :key="arg.__id"
                class="grid grid-cols-1 md:grid-cols-3 gap-2 items-center mb-2"
            >
                <a-input v-model:value="arg.name" readonly class="md:col-span-1" />
                <a-input
                    v-model:value="arg.expr"
                    :placeholder="t('text.workflow.editor.blueprint.argument_expr_placeholder')"
                    class="md:col-span-2"
                    @change="emitConfig"
                />
                <div class="md:col-span-3 flex justify-end">
                    <a-button size="small" danger @click="removeArg(idx)">
                        {{ t('text.workflow.editor.remove') }}
                    </a-button>
                </div>
            </div>
        </div>

        <div>
            <p class="text-sm font-semibold text-gray-700 mb-1">{{ t('text.workflow.editor.store_as') }}</p>
            <a-input
                v-model:value="cfg.storeAs"
                :placeholder="t('text.workflow.editor.blueprint.store_as_placeholder')"
                @change="emitConfig"
                :disabled="readOnly"
            />
            <p class="text-xs" :class="storeAsValid ? 'text-green-600' : 'text-red-600'">
                {{ t('text.workflow.editor.store_as_hint_optional') }}
            </p>
        </div>

        <div v-if="warning" class="text-xs text-amber-600">{{ warning }}</div>
    </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { nanoid } from 'nanoid'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    blueprintCatalog: {
        type: Array,
        default: () => [],
    },
    readOnly: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const cfg = reactive({
    callableType: 'service',
    class: '',
    method: '',
    argsMapping: {},
    execution: {
        mode: 'sync',
        queue: '',
        connection: '',
        delay: null,
    },
    storeAs: '',
    ...(props.modelValue || {}),
})

const typeOptions = computed(() => [
    { label: t('text.workflow.editor.blueprint.type_option.service'), value: 'service' },
    { label: t('text.workflow.editor.blueprint.type_option.job'), value: 'job' },
    { label: t('text.workflow.editor.blueprint.type_option.command'), value: 'command' },
    { label: t('text.workflow.editor.blueprint.type_option.event'), value: 'event' },
])

const modeOptions = computed(() => [
    { label: t('text.workflow.editor.blueprint.mode_option.sync'), value: 'sync' },
    { label: t('text.workflow.editor.blueprint.mode_option.async'), value: 'async' },
])

const classOptions = computed(() =>
    (props.blueprintCatalog || []).map((item) => ({
        label: item.class || item.short_name || item.display_name || item.class,
        value: item.class,
        category: item.category,
    }))
)

const methodOptions = computed(() => {
    const entry = (props.blueprintCatalog || []).find((i) => i.class === cfg.class)
    const methods = entry?.functions || []

    return methods.map((m) => {
        const hidden = !!m?.ui?.hidden
        const reason =
            m?.ui?.disallowed_reason ||
            (hidden ? t('text.workflow.editor.blueprint.callable_hidden') : '')
        const label = hidden && reason
            ? t('text.workflow.editor.blueprint.hidden_with_reason', { name: m.name, reason })
            : m.name
        return {
            label,
            value: m.name,
            disabled: hidden,
            hidden,
            reason,
        }
    })
})

const argList = reactive([])

const loadArgList = () => {
    argList.splice(0, argList.length)
    Object.entries(cfg.argsMapping || {}).forEach(([name, expr]) => {
        argList.push({ __id: nanoid(), name, expr })
    })
}

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(cfg, {
            callableType: val?.callableType || 'service',
            class: val?.class || '',
            method: val?.method || '',
            argsMapping: val?.argsMapping || {},
            execution: { mode: 'sync', queue: '', connection: '', delay: null, ...(val?.execution || {}) },
            storeAs: val?.storeAs || '',
        })
        loadArgList()
    },
    { immediate: true, deep: true }
)

const emitConfig = () => {
    const argsMapping = {}
    argList.forEach((a) => {
        if (a.name && a.expr) {
            argsMapping[a.name] = a.expr
        }
    })

    emit('update:modelValue', {
        callableType: cfg.callableType,
        class: cfg.class,
        method: cfg.method,
        argsMapping,
        execution: cfg.execution,
        storeAs: cfg.storeAs,
    })
}

const onClassChange = () => {
    cfg.method = ''
    emitConfig()
}

const addArg = () => {
    argList.push({
        __id: nanoid(),
        name: '',
        expr: '',
    })
    emitConfig()
}

const removeArg = (idx) => {
    argList.splice(idx, 1)
    emitConfig()
}

const storeAsValid = computed(() => !cfg.storeAs || cfg.storeAs.startsWith('vars.'))

const currentMethodMeta = computed(() => methodOptions.value.find((m) => m.value === cfg.method))

const warning = computed(() => {
    if (currentMethodMeta.value?.hidden) {
        return (
            currentMethodMeta.value.reason ||
            t('text.workflow.editor.blueprint.callable_hidden_cannot_select')
        )
    }
    if (cfg.callableType === 'job' && cfg.execution.mode === 'async') {
        return t('text.workflow.editor.blueprint.async_warning_long')
    }
    return ''
})
</script>

<style scoped>
.blueprint-editor {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px;
}
</style>
