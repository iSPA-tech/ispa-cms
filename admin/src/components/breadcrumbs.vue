<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="breadcrumbs pb-2">
        <!-- On narrow screens the trail takes the full row and the page actions wrap below it instead of squeezing it out. -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <!-- The trail wraps so a long leaf drops to its own line; the leaf shrinks (min-w-0) and breaks only between words. -->
            <div
                class="grow basis-full sm:basis-0 sm:flex-1 sm:min-w-[min(100%,12rem)] flex flex-wrap items-center gap-x-0.5 gap-y-0.5 *:min-h-7 *:!leading-7 *:flex *:items-center *:space-x-1"
            >
                <router-link
                    :title="$t('nav.dashboard')"
                    :to="{ name: 'dashboard' }"
                    class="text-ink rounded-sm text-xs hover:bg-surface-sunken transition-all !m-0 px-1"
                >
                    <i-icon class="size-4.5 text-ink-muted" icon="home" />
                </router-link>
                <span v-if="items.length > 0" class="text-ink-faint">
                    <i-icon class="size-4" icon="chevron-right" />
                </span>
                <template v-for="(item, index) in items" :key="index">
                    <router-link
                        v-if="item.name"
                        :class="[item.text ? 'pl-1 pr-0.25' : 'px-1']"
                        :title="item.title || item.text"
                        :to="item.to || { name: item.name }"
                        class="text-ink rounded-sm text-xs hover:bg-surface-sunken transition-all !m-0"
                    >
                        <i-icon v-if="item.icon" :icon="item.icon" class="size-4.5 text-ink-muted" />
                        <span v-if="item.text">{{ item.text }}</span>
                    </router-link>
                    <span
                        v-else
                        :class="[index === items.length - 1 && !$slots.badge ? 'grow' : '']"
                        class="min-w-0 font-semibold"
                    >
                        <i-icon v-if="item.icon" :icon="item.icon" class="size-4.5 text-ink-muted" />
                        <span
                            :class="[$slots.badge ? 'pr-2' : '']"
                            class="min-w-0 text-lg text-ink break-words sm:line-clamp-1"
                        >
                            {{ item.text }}
                        </span>
                    </span>
                    <span v-if="index < items.length - 1" class="text-ink-faint">
                        <i-icon class="size-4" icon="chevron-right" />
                    </span>
                </template>
                <div v-if="$slots.badge" class="space-x-2 flex items-center pt-0.5">
                    <slot name="badge" />
                </div>
            </div>
            <div v-if="$slots.default" class="ml-auto flex flex-wrap items-center justify-end gap-2">
                <slot name="default" />
            </div>
        </div>
        <div v-if="$slots.description" class="text-ink-faint text-xs pl-1">
            <slot name="description" />
        </div>
    </div>
</template>

<script setup>
import { defineComponent, watchEffect } from 'vue'
import { useRoute } from 'vue-router'

defineComponent({
    name: 'Breadcrumbs',
})

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['item-click'])

const route = useRoute()

// Dev-only guard: an item with neither text nor icon renders as a blank crumb.
if (import.meta.env.DEV) {
    watchEffect(() => {
        props.items.forEach((item, index) => {
            if (!item?.text && !item?.icon) {
                console.warn(
                    `[breadcrumbs] item #${index} has neither "text" nor "icon" and renders blank (route: ${String(route?.name ?? 'unknown')})`
                )
            }
        })
    })
}
</script>
