<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="visible" class="mt-2" data-testid="module-integrity" :data-state="entry.state">
        <template v-if="entry.state === 'changed'">
            <a-alert
                type="warning"
                show-icon
                :message="$t('text.modules.integrity.changed', { count: entry.changed })"
                :description="$t('text.modules.integrity.note')"
                data-testid="module-integrity-changed"
            />
            <i-button size="sm" class="mt-2" data-testid="module-integrity-toggle" @click="open = !open">
                {{ open ? $t('text.modules.integrity.hide_files') : $t('text.modules.integrity.show_files') }}
            </i-button>
        </template>
        <p v-else-if="entry.state === 'ok'" class="m-0 text-xs text-ink-faint" data-testid="module-integrity-ok">
            {{ $t('text.modules.integrity.ok') }}
        </p>
        <p v-else class="m-0 text-xs text-ink-faint" data-testid="module-integrity-unknown">
            {{ $t('text.modules.integrity.unknown') }}
        </p>

        <div v-if="entry.state === 'changed' && open" class="mt-2 min-w-0 rounded-lg border border-border p-2" data-testid="module-integrity-files">
            <ul class="m-0 flex max-h-48 list-none flex-col gap-1 overflow-auto p-0">
                <li v-for="file in entry.files" :key="file.path" class="flex min-w-0 items-start gap-2 text-xs">
                    <a-tag :color="file.status === 'missing' ? 'error' : 'warning'" class="m-0 min-w-[4.5rem] shrink-0 text-center">
                        {{ $t('text.modules.integrity.file.' + file.status) }}
                    </a-tag>
                    <span class="min-w-0 break-all">{{ file.path }}</span>
                </li>
            </ul>
            <p v-if="entry.truncated" class="m-0 mt-2 text-xs text-ink-muted">
                {{ $t('text.modules.integrity.truncated', { count: entry.files.length }) }}
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'

/**
 * Integrity note of one installed module: how many files differ from the signed package it came from, with
 * the list on demand. Information only; nothing here blocks an action. A module with no signed record shows a quiet
 * line only when it was installed at runtime (release modules have no package to compare with).
 */
const props = defineProps({
    /** `{state: ok|changed|unknown, changed, files: [{path, status}], truncated}` or null when not checked. */
    entry: { type: Object, default: null },
    /** `runtime` or `release`: where the module comes from. */
    source: { type: String, default: '' },
})

const open = ref(false)

const visible = computed(() => {
    if (!props.entry) return false
    return props.entry.state !== 'unknown' || props.source === 'runtime'
})
</script>
