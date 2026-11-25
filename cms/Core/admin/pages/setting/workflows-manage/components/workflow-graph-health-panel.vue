<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-3" data-testid="graph-health">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="m-0 text-[0.95rem] font-semibold text-gray-900">{{ t('text.workflow.builder.graph_health') }}</p>
                <p class="m-0 text-xs text-gray-500">
                    {{ t('text.workflow.builder.save_blocked') }}
                </p>
            </div>
            <a-tag :color="tone" class="!m-0">{{ summary || t('text.workflow.validation.state_not_designed') }}</a-tag>
        </div>
        <div class="space-y-2">
            <button
                v-for="check in checks"
                :key="check.key"
                class="w-full rounded-lg border border-solid bg-white p-3 text-left"
                :class="[
                    check.ok ? 'border-gray-200' : 'border-orange-300',
                    check.nodeIds?.length ? 'cursor-pointer hover:border-sky-300' : 'cursor-default',
                ]"
                type="button"
                :data-check="check.key"
                :data-ok="check.ok ? '1' : '0'"
                @click="$emit('focus-issue', check)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-700">
                            {{ check.label }}
                        </div>
                        <div class="break-words text-xs text-gray-500">
                            {{ check.message }}
                        </div>
                    </div>
                    <a-tag :color="check.ok ? 'green' : 'orange'" class="!m-0">
                        {{ check.ok ? t('text.workflow.builder.check_ok') : t('text.workflow.builder.check_fix') }}
                    </a-tag>
                </div>
            </button>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
    checks: {
        type: Array,
        default: () => [],
    },
    summary: {
        type: String,
        default: '',
    },
    tone: {
        type: String,
        default: 'default',
    },
})

defineEmits(['focus-issue'])
</script>
