<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-8">
        <!-- Research section -->
        <section v-if="researchCards.length">
            <div class="mb-4">
                <div class="text-lg font-semibold text-ink">
                    {{ t('text.agentic.task.section_research') }}
                </div>
                <div class="text-sm text-ink-muted">
                    {{ t('text.agentic.task.section_research_desc') }}
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <AgenticTaskLauncherCard
                    v-for="item in researchCards"
                    :key="item.toolName"
                    :available="item.granted"
                    :card="item.meta"
                    :loading="isLaunchTaskPending && launchingToolName === item.toolName"
                    @click="openLaunchModal(item)"
                />
            </div>
        </section>

        <!-- Proposals section -->
        <section v-if="proposalCards.length">
            <div class="mb-4">
                <div class="text-lg font-semibold text-ink">
                    {{ t('text.agentic.task.section_proposals') }}
                </div>
                <div class="text-sm text-ink-muted">
                    {{ t('text.agentic.task.section_proposals_desc') }}
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <AgenticTaskLauncherCard
                    v-for="item in proposalCards"
                    :key="item.toolName"
                    :available="item.granted"
                    :card="item.meta"
                    :loading="isLaunchTaskPending && launchingToolName === item.toolName"
                    @click="openLaunchModal(item)"
                />
            </div>
        </section>

        <!-- Empty state when no cards at all -->
        <div
            v-if="!researchCards.length && !proposalCards.length"
            class="rounded-xl border border-border bg-surface p-8 text-center text-sm text-ink-muted"
        >
            {{ t('text.agentic.task.no_tools_available') }}
        </div>

        <!-- Launch modal -->
        <a-modal
            v-model:open="launchModalVisible"
            :confirm-loading="isLaunchTaskPending"
            :mask-closable="!isLaunchTaskPending"
            :title="launchModalTitle"
            @cancel="closeLaunchModal"
            @ok="submitLaunchTask"
        >
            <div class="space-y-4 py-2">
                <!-- Module badge + handling profile -->
                <div v-if="selectedCard" class="flex flex-wrap items-center gap-2">
                    <span class="agentic-chip">
                        {{ selectedModuleLabel }}
                    </span>
                    <span v-if="selectedProfileName" class="text-xs text-ink-muted">
                        {{ t('text.agentic.task.handled_by') }}:
                        <span class="font-medium text-ink">{{ selectedProfileName }}</span>
                    </span>
                </div>

                <!-- Target selector — only shown when the tool needs a specific target -->
                <div v-if="selectedTargetEntity === 'post'">
                    <select-input-post
                        v-model="targetId"
                        :label="t('text.agentic.task.target_post_label')"
                        name="target_id"
                    />
                </div>

                <div v-else-if="selectedTargetEntity === 'product'">
                    <label class="mb-1 block text-sm font-medium text-ink">
                        {{ t('text.agentic.task.target_product_label') }}
                    </label>
                    <a-select
                        v-model:value="targetId"
                        :filter-option="false"
                        :not-found-content="productLoading ? undefined : null"
                        :options="productOptions"
                        :placeholder="t('text.f.search')"
                        allow-clear
                        class="w-full"
                        show-search
                        @search="searchProducts"
                    />
                </div>

                <!-- Optional note -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink">
                        {{ t('text.agentic.task.note_label') }}
                    </label>
                    <a-textarea
                        v-model:value="targetNote"
                        :placeholder="t('text.agentic.task.note_placeholder')"
                        :rows="3"
                        allow-clear
                    />
                </div>
            </div>

            <template #footer>
                <a-button :disabled="isLaunchTaskPending" @click="closeLaunchModal">
                    {{ t('nav.b.cancel') }}
                </a-button>
                <a-button
                    :disabled="isLaunchTaskPending"
                    :loading="isLaunchTaskPending"
                    type="primary"
                    @click="submitLaunchTask"
                >
                    {{ t('text.agentic.task.run_task') }}
                </a-button>
            </template>
        </a-modal>
    </div>
</template>

<script setup>
/**
 * AgenticTaskLauncher — the main task launcher grid for the Agentic CMS UI.
 *
 * Renders two sections (Research and Proposals) of AgenticTaskLauncherCard
 * components. Each card maps to a tool definition from taskCardMeta. Cards
 * are marked as available/unavailable based on whether the tool is granted
 * in any active profile. Clicking an available card opens a launch modal
 * where the user can provide a target ID and optional note before dispatching
 * the task via handleLaunchTask.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { debounce } from 'lodash-es'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'
import { useAgenticProfiles, useAgenticToolDefinitions } from '@/hooks'
import { useAgenticMeta } from '../composables/useAgenticMeta.js'
import { useAgenticMutations } from '../composables/useAgenticMutations.js'
import SelectInputPost from '@/components/select-input-post.vue'
import AgenticTaskLauncherCard from './AgenticTaskLauncherCard.vue'

const { t } = useI18n()

// ── Composables ───────────────────────────────────────────────────────
const { taskCardMeta, moduleLabel } = useAgenticMeta()
const { handleLaunchTask, isLaunchTaskPending } = useAgenticMutations()

const { data: definitionsData } = useAgenticToolDefinitions({}, { refetchOnWindowFocus: false })
const { data: profilesData } = useAgenticProfiles({}, { refetchOnWindowFocus: false })

// ── Granted tool set ──────────────────────────────────────────────────
/**
 * Build a Set of tool_name strings that are granted across all active profiles.
 * A tool is considered granted if it appears in any profile's granted_tools list
 * or if it exists in the definitions catalog (meaning it has been synced).
 */
const grantedToolNames = computed(() => {
    const granted = new Set()

    // A tool is considered granted if it has profile_count > 0 in the definitions catalog
    const definitions = definitionsData.value?.data || []
    for (const def of definitions) {
        if ((def.profile_count || 0) > 0) {
            granted.add(def.tool_name)
        }
    }

    return granted
})

// ── Card enrichment ───────────────────────────────────────────────────
/**
 * Map taskCardMeta entries into enriched card objects with a `granted` flag.
 */
const enrichedCards = computed(() => {
    return Object.entries(taskCardMeta).map(([toolName, meta]) => ({
        toolName,
        meta,
        granted: grantedToolNames.value.has(toolName),
    }))
})

/** Research group cards, granted cards first. */
const researchCards = computed(() => {
    return enrichedCards.value
        .filter((c) => c.meta.group === 'research')
        .sort((a, b) => (b.granted ? 1 : 0) - (a.granted ? 1 : 0))
})

/** Proposal group cards, granted cards first. */
const proposalCards = computed(() => {
    return enrichedCards.value
        .filter((c) => c.meta.group === 'proposal')
        .sort((a, b) => (b.granted ? 1 : 0) - (a.granted ? 1 : 0))
})

// ── Launch modal state ────────────────────────────────────────────────
const launchModalVisible = ref(false)
const selectedCard = ref(null)
const launchingToolName = ref(null)
const targetId = ref(null)
const targetNote = ref('')

/** The translated title shown in the modal header. */
const launchModalTitle = computed(() => {
    if (!selectedCard.value) return ''
    return t(selectedCard.value.meta.titleKey)
})

/** Module label for the badge displayed inside the modal. */
const selectedModuleLabel = computed(() => {
    if (!selectedCard.value) return ''
    return moduleLabel(selectedCard.value.meta.module)
})

/**
 * Find the active profile name that has this tool granted.
 * Shows which agent profile will handle the launched task.
 */
const selectedProfileName = computed(() => {
    if (!selectedCard.value) return null
    const profiles = profilesData.value?.data || []
    const toolName = selectedCard.value.toolName
    // Find first active profile that grants this tool
    const match = profiles.find((p) => p.status === 'active' && Array.isArray(p.granted_tools) && p.granted_tools.includes(toolName))
    if (match) return match.name
    // Fallback: any active profile
    return profiles.find((p) => p.status === 'active')?.name || null
})

/** What kind of target selector to show: 'post', 'product', or null (no target). */
const selectedTargetEntity = computed(() => {
    return selectedCard.value?.meta?.targetEntity || null
})

// ── Product search for target selector ───────────────────────────
// Product tasks pick their target from the Product module's search endpoint. Core keeps no Product admin code (Product is
// a hub module), so it makes the request itself; without Product the request fails and the picker stays empty.
const searchFilterProducts = (params) => ajax.get('product/search', { params }).then((res) => res.data || {})

const productOptions = ref([])
const productLoading = ref(false)

const searchProducts = debounce(async (keywords) => {
    if (!keywords) return
    productLoading.value = true
    try {
        const res = await searchFilterProducts({ keywords })
        productOptions.value = (res.data || []).map((p) => ({
            label: p.name,
            value: parseInt(p.id),
        }))
    } finally {
        productLoading.value = false
    }
}, 400)

async function loadInitialProducts() {
    productLoading.value = true
    try {
        const res = await searchFilterProducts({ pageSize: 10 })
        productOptions.value = (res.data || []).map((p) => ({
            label: p.name,
            value: parseInt(p.id),
        }))
    } finally {
        productLoading.value = false
    }
}

/**
 * Derive the target_type from the tool_name module prefix.
 * E.g. "content.search_posts" -> "content", "product.propose_seo" -> "product".
 */
function deriveTargetType(toolName) {
    if (!toolName) return 'general'
    const dotIndex = toolName.indexOf('.')
    return dotIndex > 0 ? toolName.substring(0, dotIndex) : 'general'
}

/**
 * Open the launch modal for a given card item.
 * @param {Object} item - enriched card object with toolName, meta, granted
 */
function openLaunchModal(item) {
    selectedCard.value = item
    targetId.value = null
    targetNote.value = ''
    launchModalVisible.value = true

    if (item.meta.targetEntity === 'product') {
        loadInitialProducts()
    }
}

/** Close the launch modal and reset state. */
function closeLaunchModal() {
    if (isLaunchTaskPending.value) return
    launchModalVisible.value = false
    selectedCard.value = null
    targetId.value = null
    targetNote.value = ''
    productOptions.value = []
}

/**
 * Submit the task launch request via handleLaunchTask.
 * On success: close modal and show a success message or link to approval inbox.
 */
async function submitLaunchTask() {
    if (!selectedCard.value || isLaunchTaskPending.value) return

    const toolName = selectedCard.value.toolName
    const targetType = deriveTargetType(toolName)
    const goal = t(selectedCard.value.meta.titleKey)

    launchingToolName.value = toolName

    try {
        const response = await handleLaunchTask(
            toolName,
            targetType,
            targetId.value || null,
            goal,
            targetNote.value || null
        )

        if (response) {
            launchModalVisible.value = false
            selectedCard.value = null
            targetId.value = null
            targetNote.value = ''
            productOptions.value = []

            // If the response contains a run ID or approval link, show it
            if (response.data?.run_id) {
                message.info(
                    t('text.agentic.msg.run_created', { id: response.data.run_id })
                )
            }
        }
    } finally {
        launchingToolName.value = null
    }
}
</script>
