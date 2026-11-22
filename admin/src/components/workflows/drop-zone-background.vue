<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<script setup>
import { Background } from '@vue-flow/background'
</script>

<template>
    <div class="dropzone-background">
        <Background :size="2" :gap="20" pattern-color="#BDBDBD" />

        <div class="overlay">
            <slot />
        </div>
    </div>
</template>
