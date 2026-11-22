<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="config" class="flex items-center text-xs space-x-2">
        <a-tooltip v-if="config.showApi" placement="top" :title="$t('text.custom.showApi')">
            <span class="text-cyan-700">APIs</span>
        </a-tooltip>
        <a-tooltip v-if="config.featured" placement="top" :title="$t('text.custom.featured')">
            <span class="text-yellow-500"><i-icon class="size-3" icon="star" /></span>
        </a-tooltip>
        <a-tooltip
            v-if="config.allowComment"
            placement="top"
            :title="$t('text.custom.allowComment')"
        >
            <span class="text-green-500"><i-icon class="size-4" icon="comment" /></span>
        </a-tooltip>
        <a-tooltip
            v-if="config.requiredLogin"
            placement="top"
            :title="$t('text.custom.requiredLogin')"
        >
            <span class="text-rose-500"><i-icon icon="account-group" class="size-4" /></span>
        </a-tooltip>
        <a-tooltip v-else placement="top" :title="$t('text.custom.public')">
            <span class="text-gray-400"><i-icon icon="earth" class="size-4" /></span>
        </a-tooltip>
    </div>
</template>

<script>
import { defineComponent } from 'vue'

export default defineComponent({
    name: 'iConfigIcons',
    props: {
        config: {
            type: Object,
            default: () => {
                return {}
            },
        },
    },
})
</script>
