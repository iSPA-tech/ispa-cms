<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <template v-if="rule('workflow_project.viewAny')">
        <router-view></router-view>
    </template>
    
    <no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { defineComponent } from 'vue'
import { rule } from '@/mixins'

defineComponent({
    name: 'WorkflowsPage',
})
</script>
