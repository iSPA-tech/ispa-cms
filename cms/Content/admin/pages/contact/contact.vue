<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="xl:flex-1 w-full">
        <router-view />
    </div>
</template>
<script setup>
import { defineComponent, onMounted, provide, ref } from 'vue'
import { emailCounter } from '@/libs'
import { legacyEmailHandedOver } from '@/utils/moduleBundles'

defineComponent({
    name: 'Contact',
})

/** Get contact counter */
const getCounter = async () => {
    // The legacy email counter is unused when Webmail receives contact-form mail and replaces the email pages.
    if (legacyEmailHandedOver()) return
    const data = await emailCounter()
}
provide('getCounter', getCounter)

onMounted(() => {
    //  Subscribe Pusher
    getCounter()
})
</script>
