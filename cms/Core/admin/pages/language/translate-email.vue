<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="w-full">
        <div
            v-if="rule('emailTemplate.create')"
            class="shrink-0 font-bold text-gray-700 flex items-center pb-4"
        >
            <div class="flex-1"></div>
            <div>
                <a-button :loading="btnState" class="btn-success" @click="openForm">
                    <span><i-icon class="size-4" icon="plus-thick" /></span>
                    <span>{{ $t('nav.b.create') }}</span>
                </a-button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { defineComponent, ref } from 'vue'
import { rule } from '@/mixins'

defineComponent({
    name: 'TranslateEmail',
})

const btnState = ref(false)
const modalState = ref(false)
const openForm = () => {
    modalState.value = true
}
</script>
