<!--
  Copyright (c) 2025-2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex items-start space-x-3">
        <i-icon v-if="icon" class="size-5 text-gray-500 mt-0.5" :icon="icon" />
        <div class="flex-1">
            <div class="text-xs text-gray-500 mb-1">{{ label }}</div>
            <div class="text-sm font-medium" :class="statusClass">
                {{ displayValue }}
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    icon: String,
    label: String,
    value: [String, Number, Boolean],
    isStatus: Boolean,
})

const normalizedStatus = computed(() => {
    const val = String(props.value).toLowerCase()

    if (['yes', 'enabled', 'true', '1'].includes(val)) {
        return 'yes'
    }

    if (['no', 'disabled', 'false', '0'].includes(val)) {
        return 'no'
    }

    return null
})

const displayValue = computed(() => {
    if (normalizedStatus.value === 'yes') {
        return t('text.yes')
    }

    if (normalizedStatus.value === 'no') {
        return t('text.no')
    }

    if (props.value === null || props.value === undefined || props.value === '') {
        return t('text.not_applicable')
    }

    return props.value
})

const statusClass = computed(() => {
    if (!props.isStatus) return 'text-gray-900'
    if (normalizedStatus.value === 'yes') {
        return 'text-green-600'
    }
    if (normalizedStatus.value === 'no') {
        return 'text-red-600'
    }
    return 'text-gray-900'
})
</script>
