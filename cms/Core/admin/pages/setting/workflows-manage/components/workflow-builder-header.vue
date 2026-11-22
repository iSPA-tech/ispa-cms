<template>
    <div class="builder-header">
        <div class="min-w-0 space-y-3">
            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600">
                <a-tag color="blue">{{ triggerEvent || t('text.workflow.builder.no_trigger') }}</a-tag>
                <span class="text-gray-500">{{ targetEntityType || t('text.workflow.builder.no_target') }}</span>
                <a-tag :color="isReadOnly ? 'default' : 'green'">
                    {{ isReadOnly ? t('text.workflow.builder.read_only') : t('text.workflow.builder.editable') }}
                </a-tag>
                <a-tag :color="graphHealthTone">
                    {{ graphHealthSummary || t('text.workflow.validation.state_not_designed') }}
                </a-tag>
                <a-tag v-if="hasUnsavedChanges" color="gold">{{ t('text.workflow.builder.unsaved_changes') }}</a-tag>
            </div>
            <p class="text-xs text-gray-500 max-w-3xl">
                {{ t('text.workflow.builder.instructions') }}
            </p>
        </div>
        <div class="builder-header-actions">
            <a-button @click="$emit('review')">{{ t('text.workflow.builder.review_graph') }}</a-button>
            <a-button
                :disabled="saving || isReadOnly"
                :loading="saving"
                :form="formId"
                html-type="submit"
                type="primary"
            >
                {{ t('text.workflow.builder.save_design') }}
            </a-button>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
    formId: {
        type: String,
        default: 'workflow-design-form',
    },
    triggerEvent: {
        type: String,
        default: '',
    },
    targetEntityType: {
        type: String,
        default: '',
    },
    isReadOnly: {
        type: Boolean,
        default: false,
    },
    graphHealthTone: {
        type: String,
        default: 'default',
    },
    graphHealthSummary: {
        type: String,
        default: '',
    },
    hasUnsavedChanges: {
        type: Boolean,
        default: false,
    },
    saving: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['review'])
</script>

<style scoped>
.builder-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.builder-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

@media screen and (max-width: 1024px) {
    .builder-header {
        flex-direction: column;
    }

    .builder-header-actions {
        width: 100%;
        justify-content: flex-end;
    }
}
</style>
