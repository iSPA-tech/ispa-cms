<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-tooltip :title="!available ? t('text.agentic.task.needs_admin_setup') : ''" placement="top">
        <div
            class="relative cursor-pointer rounded-xl border border-border bg-surface p-4 transition-all duration-150"
            :class="{
                'opacity-40 cursor-not-allowed': !available,
                'hover:border-info-soft hover:shadow-md': available && !loading,
            }"
            @click="handleClick"
        >
            <!-- Loading overlay -->
            <div
                v-if="loading"
                class="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-surface"
            >
                <a-spin />
            </div>

            <!-- Icon -->
            <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-info-soft text-info-ink">
                <span class="text-base font-semibold">{{ card.icon }}</span>
            </div>

            <!-- Title -->
            <div class="text-sm font-semibold text-ink">
                {{ t(card.titleKey) }}
            </div>

            <!-- Description -->
            <div class="mt-1 text-xs leading-relaxed text-ink-muted">
                {{ t(card.descKey) }}
            </div>

            <!-- Module badge -->
            <div class="mt-3">
                <span class="agentic-chip">
                    {{ moduleBadgeLabel }}
                </span>
            </div>
        </div>
    </a-tooltip>
</template>

<script setup>
/**
 * AgenticTaskLauncherCard — a single clickable task card used within the
 * AgenticTaskLauncher grid. Renders the tool icon, translated title/description,
 * a module badge pill, and handles availability / loading states.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { agenticModules } from '../registry'

const { t, te } = useI18n()

const props = defineProps({
    /** Card metadata object: { icon, titleKey, descKey, module, group } */
    card: {
        type: Object,
        required: true,
    },
    /** Whether the tool is currently granted and available to launch */
    available: {
        type: Boolean,
        default: true,
    },
    /** Whether a task launch is in progress for this card */
    loading: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['click'])

/** Module scope to human-readable badge label mapping. */
const moduleBadgeLabel = computed(() => {
    const map = {
        content: t('text.agentic.module.content'),
        product: t('text.agentic.module.product'),
        education: t('text.agentic.module.education'),
        core: t('text.agentic.module.core'),
    }
    // Modules that registered themselves. The label of a module that is not on this site does not exist:
    // neutral wording, never the raw key.
    for (const module of agenticModules()) {
        const label = te(module.labelKey) ? t(module.labelKey) : t('text.agentic.module.other')
        for (const key of [module.key, ...(module.aliases || [])]) {
            map[key] ??= label
        }
    }
    return map[props.card.module] || props.card.module
})

/**
 * Handle card click — only emits when the card is available and not loading.
 */
function handleClick() {
    if (!props.available || props.loading) return
    emit('click', props.card)
}
</script>
