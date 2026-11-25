<template>
    <div class="space-y-3" data-testid="loop-each-editor">
        <div class="space-y-1">
            <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.field.collectionExpr') }}</p>
            <ExpressionEditor
                :model-value="config.collectionExpr"
                variant="pick"
                :model-class="rootModel"
                :disabled="readOnly"
                @update:model-value="(value) => set('collectionExpr', value)"
            />
            <p class="mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.field.collectionExpr_hint') }}</p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="space-y-1">
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.field.itemVar') }}</p>
                <StoreNameInput
                    :model-value="config.itemVar"
                    :label="t('text.workflow.field.itemVar')"
                    :disabled="readOnly"
                    @update:model-value="(value) => set('itemVar', value)"
                />
            </div>
            <div class="space-y-1">
                <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.field.indexVar') }}</p>
                <StoreNameInput
                    :model-value="config.indexVar"
                    :label="t('text.workflow.field.indexVar')"
                    :disabled="readOnly"
                    @update:model-value="(value) => set('indexVar', value)"
                />
            </div>
        </div>
        <p class="mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.field.itemVar_hint') }}</p>

        <div class="space-y-1">
            <p class="mb-1 text-sm font-semibold text-gray-700">{{ t('text.workflow.field.maxItems') }}</p>
            <a-input-number
                :value="config.maxItems"
                :min="1"
                class="w-40"
                :aria-label="t('text.workflow.field.maxItems')"
                :disabled="readOnly"
                @update:value="(value) => set('maxItems', value)"
            />
            <p class="mb-0 break-words text-xs text-gray-500">{{ t('text.workflow.editor.loop_each.max_items_hint') }}</p>
        </div>
    </div>
</template>

<script setup>
import { reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ExpressionEditor from '@/components/workflow/inputs/ExpressionEditor.vue'
import StoreNameInput from '@/components/workflow/inputs/StoreNameInput.vue'

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
    collectionExpr: 'vars.items',
    itemVar: 'vars.item',
    indexVar: 'vars.index',
    maxItems: 100,
    ...(props.modelValue || {}),
})

watch(
    () => props.modelValue,
    (val) => {
        Object.assign(config, {
            collectionExpr: val?.collectionExpr || 'vars.items',
            itemVar: val?.itemVar || 'vars.item',
            indexVar: val?.indexVar || 'vars.index',
            maxItems: val?.maxItems ?? 100,
        })
    },
    { immediate: true, deep: true }
)

// Settings this form does not show (`_v`, keys of a newer version) are kept as they are.
const set = (key, value) => {
    config[key] = value
    emit('update:modelValue', {
        ...(props.modelValue || {}),
        collectionExpr: config.collectionExpr,
        itemVar: config.itemVar,
        indexVar: config.indexVar,
        maxItems: config.maxItems,
    })
}
</script>
