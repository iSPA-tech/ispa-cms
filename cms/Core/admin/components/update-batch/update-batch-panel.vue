<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section v-if="batch" class="i-box space-y-3" data-testid="update-batch-panel" aria-live="polite">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0 space-y-1">
                <h3 class="m-0 break-words text-base font-semibold">
                    {{
                        open
                            ? t('text.update_batch.running_title', { done: settled, total: batch.items.length })
                            : t('text.update_batch.finished_title', {
                                  done: batch.counts.done,
                                  failed: batch.counts.failed,
                                  skipped: batch.counts.skipped,
                                  left_out: batch.counts.left_out,
                              })
                    }}
                </h3>
                <p v-if="waiting" class="m-0 text-sm text-ink-muted" data-testid="update-batch-wait">{{ t('text.update_batch.wait_hint') }}</p>
                <p v-if="open && batch.stop_requested" class="m-0 text-sm text-ink-muted">{{ t('text.update_batch.stopping') }}</p>
                <p v-if="!open && batch.stopped_reason" class="m-0 text-sm text-ink-muted" data-testid="update-batch-stopped">
                    {{ t(batch.stopped_reason === 'owner' ? 'text.update_batch.stopped_owner' : 'text.update_batch.stopped_failed') }}
                </p>
            </div>
            <i-button
                v-if="open && !batch.stop_requested"
                size="sm"
                variant="tone-warning"
                :loading="busy"
                data-testid="update-batch-stop"
                @click="stop"
            >
                {{ t('text.update_batch.stop') }}
            </i-button>
            <i-button v-if="!open" size="sm" :loading="busy" data-testid="update-batch-dismiss" @click="dismiss">
                {{ t('text.update_batch.dismiss') }}
            </i-button>
        </div>
        <p v-if="error" role="alert" class="m-0 text-sm text-danger">{{ t(`text.update_batch.error.${error}`) }}</p>

        <ul class="m-0 list-none space-y-1 p-0">
            <li
                v-for="item in batch.items"
                :key="`${item.kind}:${item.key}`"
                class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm"
                data-testid="update-batch-line"
            >
                <a-tag :color="STATE_COLORS[item.state] || 'default'" class="m-0">{{ t(`text.update_batch.state.${item.state}`) }}</a-tag>
                <span class="min-w-0 break-words font-medium">{{ item.label || item.key }}</span>
                <span class="text-xs text-ink-muted">{{ t('text.update_batch.versions', { from: item.from || '–', to: item.to || '–' }) }}</span>
                <span v-if="item.state === 'running' && item.step" class="text-xs text-ink-muted">· {{ t(`text.modules.install.status.${item.step}`) }}</span>
                <span v-if="item.state === 'failed' || item.state === 'skipped'" class="text-xs text-ink-muted">
                    · {{ t(reasonKey(item.reason_code)) }}
                </span>
                <button
                    v-if="item.kind === 'module' && (item.state === 'failed' || item.state === 'running')"
                    type="button"
                    class="cursor-pointer border-0 bg-transparent p-0 text-xs text-primary-ink underline"
                    @click="emit('history')"
                >
                    {{ t('text.update_batch.see_history') }}
                </button>
                <router-link v-if="item.kind === 'workflow' && item.state === 'done' && item.setup_url" :to="item.setup_url" class="text-xs">
                    {{ t('text.update_batch.publish') }}
                </router-link>
            </li>
        </ul>

        <details v-if="batch.left_out.length" class="text-sm">
            <summary class="cursor-pointer text-ink-muted">{{ t('text.update_batch.left_out') }} ({{ batch.left_out.length }})</summary>
            <ul class="m-0 mt-1 list-none space-y-1 p-0">
                <li v-for="row in batch.left_out" :key="`${row.kind}:${row.key}`">
                    <span class="font-medium">{{ row.label || t(`text.update_batch.kind.${row.kind}`) }}</span>
                    <span class="text-ink-muted"> · {{ t(reasonKey(row.reason_code)) }}</span>
                </li>
            </ul>
        </details>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { batchError, dismissBatch, fetchCurrent, isOpen, reasonKey, stopBatch } from '../../update-batch-api'

/**
 * Progress and outcome of "Nâng cấp tất cả" (spec 2026-11-26, decision 6). Reads the open run, else the last one not
 * hidden; polls every 5 seconds while the run is open. Emits `state` with the run (or null) after every read, so the
 * page can hold its own buttons, and `history` to open the install history.
 */
const POLL_MS = 5000
const STATE_COLORS = { pending: 'default', running: 'processing', done: 'success', failed: 'error', skipped: 'warning', not_started: 'default' }

const emit = defineEmits(['state', 'history'])
const { t } = useI18n()

const batch = ref(null)
const busy = ref(false)
const error = ref('')
let timer = null

const open = computed(() => isOpen(batch.value))
/** Items no longer waiting or running. */
const settled = computed(() => (batch.value?.items || []).filter((item) => !['pending', 'running'].includes(item.state)).length)
/** Open with nothing running yet: the scheduled runner picks the next item up within about a minute. */
const waiting = computed(() => open.value && !batch.value.stop_requested && !batch.value.items.some((item) => item.state === 'running'))

const show = (value) => {
    batch.value = value && Array.isArray(value.items) ? { left_out: [], counts: {}, ...value } : null
    emit('state', batch.value)
}

const schedule = () => {
    clearTimeout(timer)
    timer = isOpen(batch.value) ? setTimeout(() => load(), POLL_MS) : null
}

/** Read the run; a failed read keeps the last known state and tries again on the next tick while the run is open. */
const load = async () => {
    try {
        show(await fetchCurrent())
        error.value = ''
    } catch (failure) {
        error.value = batchError(failure).kind
    }
    schedule()
}

const act = async (call) => {
    if (busy.value || !batch.value) return
    busy.value = true
    error.value = ''
    try {
        show(await call(batch.value.uuid))
    } catch (failure) {
        error.value = batchError(failure).kind
    } finally {
        busy.value = false
    }
    schedule()
}

const stop = () => act(stopBatch)
const dismiss = async () => {
    await act(dismissBatch)
    if (!error.value) show(null)
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
defineExpose({ load })
</script>
