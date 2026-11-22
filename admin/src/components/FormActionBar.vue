<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div
        :class="
            clsx(
                'top-1 left-1/2 -translate-x-1/2 z-20 w-full max-w-xl',
                'bg-blk-50 border-blk-200 shadow-md rounded-full border px-2 py-1',
                visible ? 'fixed' : 'hidden'
            )
        "
    >
        <div class="flex items-center space-x-2">
            <div class="flex-1 text-xs text-slate-600">
                {{ message }}
            </div>

            <a-button size="small" class="text-xs rounded-xl space-x-1" @click="$emit('discard')">
                <i-icon icon="close" class="size-4" />
                <span>{{ $t('nav.b.discard') }}</span>
            </a-button>

            <a-button
                size="small"
                class="btn-danger text-xs rounded-xl space-x-1"
                html-type="submit"
                :loading="loading"
                :disabled="loading"
            >
                <i-icon icon="content-save" class="size-3.5" />
                <span>{{ $t('nav.b.save') }}</span>
            </a-button>
        </div>
    </div>
</template>

<script setup>
import { defineComponent } from 'vue'
import clsx from 'clsx'

defineComponent({ name: 'FormActionBar' })

defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
    message: {
        type: String,
        default: '',
    },
    loading: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['discard'])
</script>
