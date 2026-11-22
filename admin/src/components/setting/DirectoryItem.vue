<!--
  Copyright (c) 2025-2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="p-4 border rounded-lg bg-gray-50">
        <div class="flex items-center space-x-2 mb-2">
            <i-icon v-if="icon" class="size-5 text-gray-600" :icon="icon" />
            <div class="text-sm font-medium text-gray-900">{{ label }}</div>
        </div>
        <div class="text-xs text-gray-600 break-all mb-1">
            {{ path || t('text.not_applicable') }}
        </div>
        <div class="text-xs font-medium" :class="sizeClass">
            {{ t('text.size') }}: {{ size || t('text.not_applicable') }}
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
    path: String,
    size: String,
})

const sizeClass = computed(() => {
    if (!props.size || props.size.includes('Error')) {
        return 'text-red-600'
    }
    return 'text-blue-600'
})
</script>
