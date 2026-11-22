<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="min-h-full bg-bg flex items-start justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full">
            <router-view></router-view>
            <div class="mt-8 pt-5 border-t border-border text-ink-faint text-xs text-center">
                {{ cmsTitle }} ({{ cmsVersion }})
                <span> {{ $t('text.auth.product_of') }} </span>
                <a class="text-primary-ink hover:text-primary-hover" href="https://ispa.io" target="_blank">iSPA Team</a>
            </div>
        </div>
    </div>
</template>
<script setup>
import { cmsTitle, cmsVersion } from '@/config'
</script>
