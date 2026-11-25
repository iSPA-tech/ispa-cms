<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
  Top bar of the workflow designer: the workflow's name, one status chip, which version runs, and the actions
  (check, save the draft, publish). It is the title of the full-screen dialog; the dialog's own × closes it.
-->
<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 pr-8 font-normal" data-testid="builder-header">
        <h1 class="m-0 min-w-0 break-words text-[17px] font-semibold leading-snug text-gray-900">{{ name }}</h1>

        <a-tooltip :title="isReadOnly ? '' : t('text.workflow.guard.draft.publish_note')">
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-solid px-2.5 py-0.5 text-[13px] font-medium"
                :class="chip.className"
                :data-state="chip.state"
                data-testid="builder-status"
            >
                <span aria-hidden="true" class="h-[7px] w-[7px] rounded-full" :class="chip.dotClassName" />
                {{ chip.label }}
            </span>
        </a-tooltip>
        <a-tag v-if="pendingCount > 0" color="orange" class="!m-0" data-testid="builder-pending">
            {{ t('text.workflow.guard.pending.count_tag', { count: pendingCount }) }}
        </a-tag>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <!-- Which version runs, when the chip is not already saying it. -->
            <template v-if="draftStatus && chip.state !== 'running'">
                <span v-if="neverPublished" class="text-[13px] text-gray-600" data-testid="builder-never-published">
                    {{ t('text.workflow.guard.draft.never_published_tag') }}
                </span>
                <span v-else class="text-[13px] text-gray-600" data-testid="builder-running-version">
                    {{ t('text.workflow.guard.draft.running_version', { number: draftStatus.published_number }) }}
                </span>
            </template>
            <a-button :icon="h(HistoryOutlined)" data-testid="builder-history" @click="$emit('history')">
                {{ t('text.workflow.guard.history.open') }}
            </a-button>
            <a-badge :count="issueCount" :offset="[-4, 2]" size="small">
                <a-button data-testid="builder-review" @click="$emit('review')">
                    {{ t('text.workflow.builder.review_graph') }}
                </a-button>
            </a-badge>
            <a-button
                v-if="canEdit"
                :disabled="isReadOnly || saving || loading"
                :loading="saving && !publishing"
                :type="canPublish ? 'default' : 'primary'"
                data-testid="builder-save"
                @click="$emit('save')"
            >
                {{ t('text.workflow.builder.save_design') }}
            </a-button>
            <a-button
                v-if="canPublish"
                :disabled="saving || loading || !hasSteps"
                :loading="publishing"
                data-testid="builder-publish"
                type="primary"
                @click="$emit('publish')"
            >
                {{ t('text.workflow.guard.publish.action') }}
            </a-button>
        </div>
    </div>
</template>

<script setup>
import { HistoryOutlined } from '@ant-design/icons-vue'
import { computed, h } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    name: {
        type: String,
        default: '',
    },
    isReadOnly: {
        type: Boolean,
        default: false,
    },
    /** Checks of the diagram that do not pass yet (shown on the "Check the diagram" button). */
    issueCount: {
        type: Number,
        default: 0,
    },
    hasUnsavedChanges: {
        type: Boolean,
        default: false,
    },
    /** `{ differs, published_number }` of the saved draft, or null while it is not known. */
    draftStatus: {
        type: Object,
        default: null,
    },
    /** Sensitive changes of this workflow that are not live yet. */
    pendingCount: {
        type: Number,
        default: 0,
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    canPublish: {
        type: Boolean,
        default: false,
    },
    hasSteps: {
        type: Boolean,
        default: false,
    },
    saving: {
        type: Boolean,
        default: false,
    },
    publishing: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['review', 'history', 'save', 'publish'])

const neverPublished = computed(() => props.draftStatus?.published_number === null || props.draftStatus?.published_number === undefined)

// One chip: what the diagram on screen is compared with what is stored and what runs.
const chip = computed(() => {
    if (props.isReadOnly) {
        return {
            state: 'read_only',
            label: t('text.workflow.builder.read_only'),
            className: 'border-gray-300 bg-gray-50 text-gray-700',
            dotClassName: 'bg-gray-500',
        }
    }
    if (props.hasUnsavedChanges) {
        return {
            state: 'unsaved',
            label: t('text.workflow.canvas.status.unsaved'),
            className: 'border-amber-300 bg-amber-50 text-amber-800',
            dotClassName: 'bg-amber-600',
        }
    }
    if (props.draftStatus && !neverPublished.value && !props.draftStatus.differs) {
        return {
            state: 'running',
            label: t('text.workflow.canvas.status.running', { number: props.draftStatus.published_number }),
            className: 'border-green-300 bg-green-50 text-green-800',
            dotClassName: 'bg-green-600',
        }
    }
    return {
        state: 'saved',
        label: t('text.workflow.canvas.status.saved'),
        className: 'border-sky-300 bg-sky-50 text-sky-800',
        dotClassName: 'bg-sky-600',
    }
})
</script>
