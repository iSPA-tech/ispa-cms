<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section v-if="total > 0" class="i-box flex flex-wrap items-center justify-between gap-3" data-testid="update-batch-band">
        <div class="min-w-0 space-y-1">
            <h3 class="m-0 text-base font-semibold">{{ t('text.update_batch.title') }}</h3>
            <p class="m-0 break-words text-sm text-ink-muted">{{ t('text.update_batch.counts', counts) }}</p>
            <p v-if="coreUpdateAvailable" class="m-0 break-words text-sm text-warning-ink" data-testid="update-batch-core-first">
                {{ t('text.update_batch.core_first') }}
            </p>
        </div>
        <i-button variant="primary" size="md" :disabled="busy" data-testid="update-batch-open" @click="dialogOpen = true">
            {{ t('text.update_batch.start_all') }}
        </i-button>
        <update-batch-dialog :open="dialogOpen" :kinds="KINDS" @close="dialogOpen = false" @started="onStarted" />
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { KINDS, countsByKind, fetchPlan } from '../../update-batch-api'
import UpdateBatchDialog from './update-batch-dialog.vue'

/**
 * "Bản cập nhật" on the Modules page (upgrade-all spec 2026-11-26): how many modules, widgets and workflow templates
 * have a newer hub version that one run can take, the advice to update iSPA CMS first, and "Nâng cấp tất cả".
 * Hidden when nothing can be upgraded in one go; the button is held while a run is open (`busy`). A failed read hides
 * the band: the per-item buttons stay. Emits `started` with the run.
 */
defineProps({
    coreUpdateAvailable: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
})
const emit = defineEmits(['started'])
const { t } = useI18n()

const items = ref([])
const dialogOpen = ref(false)

const counts = computed(() => countsByKind(items.value))
const total = computed(() => items.value.length)

const load = async () => {
    try {
        const plan = await fetchPlan(KINDS)
        items.value = Array.isArray(plan?.items) ? plan.items : []
    } catch {
        items.value = []
    }
}

const onStarted = (batch) => {
    dialogOpen.value = false
    emit('started', batch)
}

onMounted(load)
defineExpose({ load })
</script>
