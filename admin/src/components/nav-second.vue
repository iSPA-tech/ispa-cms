<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <nav
        :aria-label="$t('text.ui.nav-second.sections')"
        :class="[
            'h-full !w-64 shrink-0 border-r border-blue-slate-200 transition-transform duration-300 xl:relative',
        ]"
    >
        <div class="p-2 flex items-center">
            <div class="font-semibold text-slate-800 flex-1">{{ title }}</div>
            <div v-if="additionButton && additionButton.rule && rule(additionButton.rule)">
                <router-link :to="{ name: additionButton.name }">
                    <a-button type="primary" size="small" class="btn-help text-xs">
                        <i-icon class="size-3" icon="plus-thick" />
                        {{
                            getLastItem(additionButton.text)
                                .map((t) => $t(t))
                                .join(' ')
                        }}
                    </a-button>
                </router-link>
            </div>
        </div>
        <div class="pt-1 min-h-full overflow-y-auto space-y-0.5">
            <template v-for="(nav, ind) in nav" :key="ind">
                <div
                    v-if="nav.break"
                    :class="{ hidden: !nav.rule || !rule(nav.rule) }"
                    class="px-3 py-0 my-1 border-t"
                />
                <template v-else>
                    <template
                        v-if="
                            nav.children &&
                            nav.children.length > 0 &&
                            isSameNav(nav.name, route.name) &&
                            rule(nav.rule)
                        "
                    >
                        <div class="px-2">
                            <div
                                :class="
                                    clsx(
                                        'text-slate-600',
                                        'rounded-sm flex items-center px-2 py-1.5',
                                        'text-sm space-x-3 group'
                                    )
                                "
                            >
                                <span v-if="nav.icon" class="text-slate-900">
                                    <i-icon
                                        icon="chevron-down"
                                        class="size-4 hidden group-hover:block"
                                    />

                                    <i-icon class="size-4 group-hover:hidden" :icon="nav.icon" />
                                </span>
                                <span>
                                    {{
                                        getLastItem(nav.text)
                                            .map((t) => $t(t))
                                            .join(' ')
                                    }}
                                </span>
                            </div>
                            <div class="pl-4 mb-1">
                                <ul class="pl-2 py-0.5 border-l space-y-0.5">
                                    <li v-for="(child, cInd) in nav.children" :key="cInd">
                                        <a-tooltip v-if="rule(child.rule) && navDisabled(child)" placement="right" :title="$t(navDisabled(child))">
                                            <span class="rounded-sm flex items-center px-2 py-1.5 text-sm space-x-3 text-slate-600 opacity-50 cursor-not-allowed" aria-disabled="true" :data-nav-disabled="child.name">
                                                <span v-if="child.icon"><i-icon class="size-4" :icon="child.icon" /></span>
                                                <span>{{ getLastItem(child.text).map((t) => $t(t)).join(' ') }}</span>
                                            </span>
                                        </a-tooltip>
                                        <router-link
                                            v-else-if="rule(child.rule)"
                                            :class="
                                                clsx(
                                                    activeNav(child.name)
                                                        ? 'bg-black-100 text-slate-800'
                                                        : 'text-slate-600',
                                                    'rounded-sm flex items-center px-2 py-1.5 hover:text-slate-700',
                                                    'text-sm space-x-3 hover:bg-slate-100'
                                                )
                                            "
                                            :to="{ name: child.name }"
                                        >
                                            <span v-if="child.icon">
                                                <i-icon
                                                    :class="{
                                                        'text-slate-900': activeNav(child.name),
                                                    }"
                                                    class="size-4"
                                                    :icon="child.icon"
                                                />
                                            </span>
                                            <span>
                                                {{
                                                    getLastItem(child.text)
                                                        .map((t) => $t(t))
                                                        .join(' ')
                                                }}
                                            </span>
                                        </router-link>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div v-if="rule(nav.rule)" class="px-2 h-8">
                            <router-link
                                :class="
                                    clsx(
                                        activeNav(nav.name)
                                            ? 'bg-black-100 text-slate-800'
                                            : 'text-slate-600',
                                        'rounded-sm flex items-center px-2 py-1.5 hover:text-slate-700',
                                        'text-sm space-x-3 hover:bg-slate-100'
                                    )
                                "
                                :to="{ name: nav.name }"
                            >
                                <span v-if="nav.icon">
                                    <i-icon
                                        :class="{ 'text-slate-900': activeNav(nav.name) }"
                                        class="size-4"
                                        :icon="nav.icon"
                                    />
                                </span>
                                <span>
                                    {{
                                        getLastItem(nav.text)
                                            .map((t) => $t(t))
                                            .join(' ')
                                    }}
                                </span>
                                <span v-if="nav.counter >= 0" class="flex-1 text-xs text-right">
                                    <span
                                        class="bg-slate-700 text-slate-200 px-1.5 py-0.5 rounded-full"
                                    >
                                        {{ nav.counter }}
                                    </span>
                                </span>
                            </router-link>
                        </div>
                    </template>
                </template>
            </template>
        </div>
    </nav>
</template>

<script setup>
import { defineComponent, inject } from 'vue'
import { rule, getLastItem } from '@/mixins'
import { useRoute } from 'vue-router'
import clsx from 'clsx'

const props = defineProps({
    title: { type: String, default: null },
    nav: {
        type: Object,
        default: () => {},
    },
    additionButton: {
        type: Object,
        default: () => {},
    },
})

defineComponent({
    name: 'NavSecond',
})

const isSameNav = inject('isSameNav')
const route = useRoute()

const activeNav = (name) => {
    return name === route.name || (name && route.name.includes(name))
}
// Same rule as the main sidebar: a child whose `disabled` (a translation key, or a function returning one) is set is greyed out with that reason.
const navDisabled = (item) => {
    const value = typeof item?.disabled === 'function' ? item.disabled() : item?.disabled
    return typeof value === 'string' ? value : ''
}
</script>
