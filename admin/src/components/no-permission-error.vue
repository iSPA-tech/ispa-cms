<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="px-2 md:px-1 py-4 mb-12 h-80 flex flex-col items-center">
        <i-alert accent class="mx-auto" type="danger">
            <h2 class="mb-2 font-bold text-lg">{{ $t('mgs.no.permission') }}</h2>
        </i-alert>
        <a-button class="btn-warning" @click="router.push('/')">
            <span><i-icon icon="undo" class="size-4" /></span>
            <span>{{ $t('nav.dashboard') }}</span>
        </a-button>
    </div>
</template>
<script>
import { defineComponent } from 'vue'
import { useRouter } from 'vue-router'

export default defineComponent({
    name: 'NoPermissionError',
    setup() {
        const router = useRouter()
        return {
            router,
        }
    },
})
</script>
