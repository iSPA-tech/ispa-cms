<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex-1 xl:overflow-y-auto bg-white shadow-xl">
        <div class="w-full">
            <div class="px-4 sm:px-6 lg:px-8 py-4 mb-12 pt-6">
                <router-view />
            </div>
        </div>
    </div>
</template>

<script setup>
import { defineComponent } from 'vue'

defineComponent({
    name: 'Category',
})
</script>
