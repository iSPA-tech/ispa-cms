<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <template v-if="allowed">
        <router-view></router-view>
    </template>
    
    <no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { computed, defineComponent } from 'vue'
import { useRoute } from 'vue-router'
import { rule } from '@/mixins'

const route = useRoute()
// The credentials page sits in this group with a rule of its own; every other page needs the right to see workflows.
const allowed = computed(() => rule(route.name === 'workflow-credentials' ? 'workflow_credential.manage' : 'workflow_project.viewAny'))

defineComponent({
    name: 'WorkflowsPage',
})
</script>
