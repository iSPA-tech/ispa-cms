<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-drawer :open="open" width="min(520px, 100vw)" :title="t('text.update_batch.start_all')" @close="close">
        <div class="space-y-4" data-testid="update-batch-dialog">
            <div v-if="state.loading" aria-busy="true">
                <a-skeleton active :paragraph="{ rows: 5 }" />
            </div>
            <div v-else-if="state.loadError" role="alert" class="space-y-2" data-testid="update-batch-load-error">
                <p class="m-0 text-sm text-danger">{{ t(`text.update_batch.error.${state.loadError}`) }}</p>
                <i-button size="sm" @click="load">{{ t('text.modules.install.retry') }}</i-button>
            </div>
            <template v-else-if="state.plan">
                <a-alert v-if="!state.plan.runner_ready" type="warning" show-icon :message="t('text.update_batch.error.no_runner')" />
                <p v-if="!items.length && !leftOut.length" class="m-0 text-sm text-ink-muted" data-testid="update-batch-nothing">
                    {{ t('text.update_batch.nothing') }}
                </p>

                <section v-if="items.length" class="space-y-3" aria-labelledby="update-batch-will">
                    <h3 id="update-batch-will" class="m-0 text-base font-semibold">{{ t('text.update_batch.will_upgrade') }}</h3>
                    <div v-for="group in groups" :key="group.kind" class="space-y-1">
                        <h4 class="m-0 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            {{ t(`text.update_batch.kind.${group.kind}`) }} ({{ group.items.length }})
                        </h4>
                        <ul class="m-0 list-none space-y-1 p-0">
                            <li
                                v-for="item in group.items"
                                :key="`${item.kind}:${item.key}`"
                                class="rounded-md bg-surface-sunken px-3 py-2"
                                data-testid="update-batch-item"
                                :data-key="item.key"
                            >
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <span class="min-w-0 break-words font-medium">{{ item.label || item.key }}</span>
                                    <span class="text-xs text-ink-muted">{{ t('text.update_batch.versions', { from: item.from || '–', to: item.to || '–' }) }}</span>
                                </div>
                                <p v-if="item.kind === 'widget' && item.pages" class="m-0 text-xs text-ink-muted">
                                    {{ t('text.update_batch.pages', { count: item.pages }) }}
                                </p>
                                <p v-if="item.kind === 'workflow'" class="m-0 text-xs text-ink-muted">{{ t('text.update_batch.draft_note') }}</p>
                            </li>
                        </ul>
                    </div>
                </section>

                <section v-if="leftOut.length" class="space-y-2" aria-labelledby="update-batch-left" data-testid="update-batch-left-out">
                    <h3 id="update-batch-left" class="m-0 text-base font-semibold">{{ t('text.update_batch.left_out') }}</h3>
                    <ul class="m-0 list-none space-y-1 p-0">
                        <li v-for="row in leftOut" :key="`${row.kind}:${row.key}`" class="text-sm">
                            <span class="font-medium">{{ row.label || t(`text.update_batch.kind.${row.kind}`) }}</span>
                            <span class="text-ink-muted"> · {{ t(reasonKey(row.reason_code)) }}</span>
                        </li>
                    </ul>
                </section>

                <label v-if="hasModules" class="flex items-center gap-3">
                    <a-switch v-model:checked="state.backup" />
                    <span class="text-sm">{{ t('text.update_batch.backup') }}</span>
                </label>

                <a-alert v-if="state.startError" type="error" show-icon :message="t(`text.update_batch.error.${state.startError}`)" />
            </template>
        </div>
        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <i-button size="sm" @click="close">{{ t('text.modules.install.action.cancel') }}</i-button>
                <i-button
                    size="sm"
                    variant="primary"
                    :disabled="!canStart"
                    :loading="state.starting"
                    data-testid="update-batch-start"
                    @click="start"
                >
                    {{ t('text.update_batch.start_all') }}
                </i-button>
            </div>
        </template>
    </a-drawer>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { KINDS, batchError, fetchPlan, reasonKey, startBatch } from '../../update-batch-api'

/**
 * The one confirmation of "Nâng cấp tất cả" (spec 2026-11-26, decision 5): what will be upgraded, by kind, what is
 * left out and why, one backup switch for the modules. `kinds` narrows the run (the widget and workflow libraries
 * open it for their own kind). Emits `started` with the run, and `close`.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    kinds: { type: Array, default: () => KINDS },
})
const emit = defineEmits(['started', 'close'])
const { t } = useI18n()

const state = reactive({ loading: false, loadError: '', plan: null, backup: true, starting: false, startError: '' })

const items = computed(() => (Array.isArray(state.plan?.items) ? state.plan.items : []))
const leftOut = computed(() => (Array.isArray(state.plan?.left_out) ? state.plan.left_out : []))
const groups = computed(() =>
    KINDS.map((kind) => ({ kind, items: items.value.filter((item) => item.kind === kind) })).filter((group) => group.items.length),
)
const hasModules = computed(() => items.value.some((item) => item.kind === 'module'))
const canStart = computed(() => !state.loading && !state.starting && items.value.length > 0 && state.plan?.runner_ready === true)

const load = async () => {
    state.loading = true
    state.loadError = ''
    try {
        state.plan = await fetchPlan(props.kinds)
    } catch (error) {
        state.plan = null
        state.loadError = batchError(error).kind
    } finally {
        state.loading = false
    }
}

/** Start the run. A second click while the request runs sends nothing; the flag is released only on an error. */
const start = async () => {
    if (state.starting || !canStart.value) return
    state.starting = true
    state.startError = ''
    try {
        const batch = await startBatch({ kinds: props.kinds, items: items.value, backup: hasModules.value ? state.backup : true })
        emit('started', batch)
    } catch (error) {
        state.startError = batchError(error).kind
        state.starting = false
        if (state.startError === 'changed') {
            const message = state.startError
            await load()
            state.startError = message
        }
    }
}

const close = () => emit('close')

watch(
    () => props.open,
    (open) => {
        if (!open) return
        state.backup = true
        state.starting = false
        state.startError = ''
        load()
    },
    { immediate: true },
)
</script>
