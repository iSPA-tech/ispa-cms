<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-tag v-if="publisher" :color="color" data-testid="publisher-badge" :data-type="publisher.type">
        {{ $t('text.modules.store.publisher.' + publisher.type, { name: publisher.name }) }}
    </a-tag>
</template>

<script setup>
import { computed } from 'vue'

/**
 * Who publishes a module: iSPA, a partner or the community. Renders nothing without a publisher.
 */
const props = defineProps({
    publisher: { type: Object, default: null },
})

const color = computed(() => ({ ispa: 'processing', partner: 'purple' })[props.publisher?.type] || 'default')
</script>
