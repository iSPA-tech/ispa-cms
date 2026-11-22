<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="isVisible" class="agentic-inline-assist">
        <!-- Idle state: show button -->
        <a-tooltip v-if="state === 'idle'" :title="t('text.agentic.inline.tooltip')">
            <a-button
                size="small"
                type="dashed"
                :loading="false"
                @click="handleLaunch"
            >
                <template #icon><span>✨</span></template>
                {{ t('text.agentic.inline.suggest') }}
            </a-button>
        </a-tooltip>

        <!-- Running state -->
        <div v-else-if="state === 'running'" class="agentic-inline-assist__running">
            <a-spin size="small" />
            <span class="ml-2 text-xs text-slate-500">{{ t('text.agentic.inline.running') }}</span>
        </div>

        <!-- Result state -->
        <div v-else-if="state === 'result'" class="agentic-inline-assist__result">
            <div class="rounded-lg border border-blue-200 bg-blue-50/50 p-3">
                <div class="mb-2 flex items-center gap-2 text-xs font-semibold text-blue-700">
                    <span>💡</span>
                    {{ t('text.agentic.inline.suggestion_from_ai') }}
                </div>

                <!-- Result content preview -->
                <div v-if="resultData" class="mb-3 max-h-40 overflow-y-auto rounded border border-blue-100 bg-white p-2 text-sm">
                    <template v-if="typeof resultData === 'string'">{{ resultData }}</template>
                    <template v-else-if="resultData.proposed_change">
                        <div v-for="(value, key) in resultData.proposed_change" :key="key" class="mb-1">
                            <span class="font-medium text-slate-600">{{ key }}:</span>
                            <span class="ml-1 text-slate-800">{{ typeof value === 'string' ? value : JSON.stringify(value) }}</span>
                        </div>
                    </template>
                    <template v-else>
                        <pre class="whitespace-pre-wrap text-xs">{{ JSON.stringify(resultData, null, 2) }}</pre>
                    </template>
                </div>

                <!-- Action buttons based on tool type -->
                <div class="flex flex-wrap gap-2">
                    <!-- Read-only: just close -->
                    <template v-if="resultType === 'read_only'">
                        <a-button size="small" @click="dismiss">
                            {{ t('text.agentic.inline.close') }}
                        </a-button>
                    </template>

                    <!-- Draft: accept (paste into field) + dismiss -->
                    <template v-else-if="resultType === 'draft'">
                        <a-button size="small" type="primary" @click="handleAccept">
                            {{ t('text.agentic.inline.accept') }}
                        </a-button>
                        <a-button size="small" @click="dismiss">
                            {{ t('text.agentic.inline.dismiss') }}
                        </a-button>
                    </template>

                    <!-- Staged mutation: link to approval inbox -->
                    <template v-else>
                        <router-link
                            v-if="approvalId"
                            :to="{ name: 'setting-agentic-approval-detail', params: { id: approvalId } }"
                        >
                            <a-button size="small" type="primary">
                                {{ t('text.agentic.inline.view_in_inbox') }}
                            </a-button>
                        </router-link>
                        <a-button size="small" @click="dismiss">
                            {{ t('text.agentic.inline.dismiss') }}
                        </a-button>
                    </template>
                </div>
            </div>
        </div>

        <!-- Error state -->
        <div v-else-if="state === 'error'" class="agentic-inline-assist__error">
            <span class="text-xs text-red-500">{{ errorMessage }}</span>
            <a-button size="small" class="ml-2" @click="dismiss">
                {{ t('text.agentic.inline.retry') }}
            </a-button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgenticPermissions } from '@core-admin/pages/setting/agentic/composables/useAgenticPermissions'
import { useAgenticMutations } from '@core-admin/pages/setting/agentic/composables/useAgenticMutations'

const props = defineProps({
    toolName: { type: String, required: true },
    targetId: { type: [Number, String], required: true },
    targetType: { type: String, required: true },
    fields: { type: Array, default: () => [] },
})

const emit = defineEmits(['accept', 'proposal-created', 'result-preview'])

const { t } = useI18n()
const { canViewAgentic } = useAgenticPermissions()
const { handleLaunchTask } = useAgenticMutations()

const state = ref('idle') // idle | running | result | error
const resultData = ref(null)
const resultType = ref('read_only') // read_only | draft | staged
const approvalId = ref(null)
const errorMessage = ref('')

const isVisible = computed(() => canViewAgentic.value)

async function handleLaunch() {
    state.value = 'running'
    resultData.value = null
    approvalId.value = null
    errorMessage.value = ''

    try {
        const response = await handleLaunchTask(
            props.toolName,
            props.targetType,
            props.targetId,
            t('text.agentic.inline.ai_assist', { fields: props.fields.join(', ') }),
            null,
        )

        if (!response) {
            state.value = 'error'
            errorMessage.value = t('text.agentic.inline.error')
            return
        }

        const run = response?.data?.run
        const responseApprovalId = response?.data?.approval_id

        if (responseApprovalId) {
            // Staged mutation — approval created
            resultType.value = 'staged'
            approvalId.value = responseApprovalId
            resultData.value = run?.latest_artifact || { message: t('text.agentic.msg.proposal_created') }
            emit('proposal-created', { approvalId: responseApprovalId, run })
        } else if (run?.status === 'completed') {
            // Read-only or draft result
            const latestStep = run?.steps?.[run.steps.length - 1]
            resultData.value = latestStep?.observation_summary || run?.reasoning_summary || t('text.agentic.label.completed')

            // Determine if this is a draft (can accept) or read-only
            resultType.value = props.fields.length > 0 ? 'draft' : 'read_only'
            emit('result-preview', { data: resultData.value, run })
        } else {
            resultData.value = run || response
            resultType.value = 'read_only'
        }

        state.value = 'result'
    } catch (error) {
        state.value = 'error'
        errorMessage.value = error?.message || t('text.agentic.inline.error')
    }
}

function handleAccept() {
    emit('accept', resultData.value)
    dismiss()
}

function dismiss() {
    state.value = 'idle'
    resultData.value = null
    approvalId.value = null
    errorMessage.value = ''
}
</script>

<style scoped>
.agentic-inline-assist {
    margin-top: 0.5rem;
}
.agentic-inline-assist__running {
    display: flex;
    align-items: center;
    padding: 0.5rem 0;
}
</style>
