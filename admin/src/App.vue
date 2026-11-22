<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->
<template>
    <a-config-provider :locale="antLocale" :theme="antdThemeConfig">
        <template v-if="siteGate === 'ready'">
            <router-view v-if="usesPublicShell || (usesAccountShell && token != null)" />
            <AuthSignIn
                v-else-if="token == null && !['auth-sign-in', 'auth-forgot'].includes(route.name)"
            />
            <a-layout v-else class="leading-6 h-screen overflow-hidden flex flex-col">
                <a-layout-header
                    class="header shrink-0 bg-white border-b dark:border-gray-700 dark:bg-slate-950 h-auto max-h-12 px-3 lg:px-4 py-2 leading-6"
                >
                    <div class="flex items-center place-content-between gap-4">
                        <div>
                            <img
                                :alt="cmsTitle"
                                :src="logoAuthUrl"
                                class="h-8 w-auto dark:hidden"
                            />
                            <img
                                :alt="cmsTitle"
                                :src="logoDarkUrl"
                                class="h-8 w-auto hidden dark:block"
                            />
                        </div>
                        <div class="flex-1 flex items-center justify-center">
                            <input
                                :class="
                                    clsx(
                                        'w-full max-w-md m-0 px-3 py-0.5 text-sm border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-cyan-500',
                                        'dark:bg-slate-900 bg-slate-100',
                                        'placeholder:text-gray-400 dark:placeholder:text-gray-500 placeholder:text-xs'
                                    )
                                "
                                :placeholder="t('text.ui.shell.search')"
                                type="search"
                            />
                        </div>
                        <div class="flex items-center">
                            <background-task-bar />
                            <my-tasks-badge />
                            <notification-bell />
                            <a-dropdown>
                                <template #overlay>
                                    <a-menu>
                                        <a-menu-item>
                                            <div v-if="accountData" class="flex items-center gap-3 py-3">
                                                <a-avatar
                                                    v-if="accountAvatarUrl"
                                                    :size="40"
                                                    :src="accountAvatarUrl"
                                                />
                                                <span
                                                    v-else
                                                    class="inline-flex size-10 items-center justify-center rounded-full border border-gray-300 bg-slate-100 text-slate-500"
                                                >
                                                    <i-icon icon="account" />
                                                </span>
                                                <div class="flex flex-col">
                                                    <span class="leading-6 text-gray-900 font-semibold">
                                                        {{ accountData.name }}
                                                    </span>
                                                    <span
                                                        class="text-xs text-gray-500 inline-flex space-x-2"
                                                    >
                                                        <i-icon
                                                            class="size-4 text-gray-700"
                                                            icon="fingerprint"
                                                        />
                                                        {{ accountData.username }}
                                                    </span>
                                                </div>
                                            </div>
                                        </a-menu-item>
                                        <a-menu-divider />
                                        <a-menu-item>
                                            <router-link
                                                :to="{
                                                    name: 'account-dashboard',
                                                }"
                                            >
                                                {{ $t('nav.a.account-dashboard') }}
                                            </router-link>
                                        </a-menu-item>
                                        <a-menu-item>
                                            <router-link :to="{ name: 'account-setting' }">
                                                {{ $t('nav.a.account-setting') }}
                                            </router-link>
                                        </a-menu-item>
                                        <a-menu-item>
                                            <router-link
                                                :to="{
                                                    name: 'account-password',
                                                }"
                                            >
                                                {{ $t('nav.a.account-password') }}
                                            </router-link>
                                        </a-menu-item>
                                        <a-menu-divider />
                                        <a-menu-item @click="switchTheme">
                                            <div class="flex items-center space-x-2">
                                                <i-icon class="size-4" icon="theme-light-dark" />
                                                <span>
                                                    {{
                                                        savedTheme === 'dark'
                                                            ? $t('nav.b.light-mode')
                                                            : $t('nav.b.dark-mode')
                                                    }}
                                                    {{ savedTheme ? '(' + savedTheme + ')' : '' }}
                                                </span>
                                            </div>
                                        </a-menu-item>
                                        <a-menu-item @click="changeLanguage">
                                            <div class="flex items-center space-x-2">
                                                <i-icon class="size-4" icon="theme-light-dark" />
                                                <span>
                                                    {{ $t('nav.a.languages') }}
                                                    ({{ globalLocale }})
                                                </span>
                                            </div>
                                        </a-menu-item>
                                        <a-menu-divider />
                                        <a-menu-item @click="signOut">
                                            <div class="flex items-center space-x-2">
                                                <i-icon class="size-4" icon="logout" />
                                                <span>{{ $t('nav.b.sign-out') }}</span>
                                            </div>
                                        </a-menu-item>
                                        <a-menu-divider />
                                        <a-menu-item disabled>
                                            <span class="text-gray-400 text-xs text-center block">
                                                {{ cmsTitle }}
                                                {{ cmsVersion }} build
                                                {{ buildVersion }}
                                            </span>
                                        </a-menu-item>
                                    </a-menu>
                                </template>

                                <button
                                    :class="
                                        clsx(
                                            'hidden lg:inline-flex text-sm border text-gray-700 p-0.5 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200',
                                            'size-6 rounded-full flex items-center justify-center focus:outline-hidden focus:ring-2 focus:ring-inset focus:ring-cyan-500',
                                            'dark:border-slate-700 border-gray-300'
                                        )
                                    "
                                >
                                    <img
                                        v-if="accountAvatarUrl"
                                        :alt="accountData?.name || 'Account avatar'"
                                        :src="accountAvatarUrl"
                                        class="size-6 rounded-full object-cover"
                                    />
                                    <i-icon v-else icon="account-settings" />
                                </button>
                            </a-dropdown>
                            <button
                                class="lg:hidden text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200"
                                @click="sidebarOpen = !sidebarOpen"
                            >
                                <i-icon icon="menu" />
                            </button>
                        </div>
                    </div>
                </a-layout-header>
                <div
                    v-if="isImpersonating"
                    class="bg-amber-100 border-b border-amber-200 px-3 lg:px-4 py-1 text-xs text-amber-900 flex items-center justify-between gap-2"
                >
                    <p class="m-0">
                        {{ t('text.sign-in-as.current-account') }}
                        <span class="font-semibold">
                            {{ impersonationDisplayName }}
                        </span>
                    </p>
                    <a-button size="small" type="default" @click="exitImpersonation">
                        {{ t('nav.b.cancel') }}
                    </a-button>
                </div>
                <!-- Editable demo site: the Demo module marks answers to the shared demo account with its daily reset time. -->
                <div
                    v-if="demoSiteReset"
                    role="status"
                    data-demo-site-banner
                    class="bg-sky-100 border-b border-sky-200 px-3 lg:px-4 py-1 text-xs text-sky-900"
                >
                    <p class="m-0 font-medium">{{ t('text.demo.site_banner', { time: demoSiteReset }) }}</p>
                </div>
                <!-- Shown only while the signed-in account is the read-only demo account (the Demo project marks its answers). -->
                <div
                    v-else-if="demoReadOnly"
                    role="status"
                    data-demo-admin-banner
                    class="bg-sky-100 border-b border-sky-200 px-3 lg:px-4 py-1 text-xs text-sky-900"
                >
                    <p class="m-0 font-medium">{{ t('text.demo.admin_banner') }}</p>
                </div>
                <a-layout class="h-full lg:pl-60 pl-0 relative flex-1 min-h-0 overflow-hidden">
                    <a-layout-sider
                        :class="clsx(sidebarOpen ? 'left-0' : '-left-full lg:left-0')"
                        class="absolute bg-blk-200 lg:bg-blk-200/60 dark:bg-slate-950 lg:dark:bg-slate-950 h-full z-10 top-0 transistion-all duration-300 ease-in-out p-3"
                        width="240"
                    >
                        <ul
                            class="space-y-1 h-full overflow-y-auto overscroll-contain pb-10 pr-1 -mr-1 [scrollbar-width:thin] [scrollbar-color:rgb(148_163_184_/_0.5)_transparent]"
                        >
                            <template v-for="nav in mainNav" :key="nav.name">
                                <li v-if="rule(nav.rule) && !navHidden(nav)">
                                    <div>
                                        <router-link
                                            :class="
                                                clsx(
                                                    activeNav(nav.name)
                                                        ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-800 dark:text-slate-100'
                                                        : 'text-slate-800 hover:bg-slate-50/30 hover:text-slate-700 dark:text-slate-300 dark:hover:bg-slate-800/60 dark:hover:text-slate-100',
                                                    'rounded-lg flex items-center px-2 py-1',
                                                    'text-sm font-medium space-x-2'
                                                )
                                            "
                                            :to="{ name: nav.name }"
                                        >
                                            <i-icon :icon="nav.icon" class="size-4" />
                                            <span>
                                                {{ $t(nav.text) }}
                                            </span>
                                            <nav-badge :name="nav.name" />
                                        </router-link>
                                    </div>
                                    <div
                                        v-if="
                                            nav.children &&
                                            nav.children.length > 0 &&
                                            activeGroupNav(nav.group)
                                        "
                                        class="pt-1 relative"
                                    >
                                        <div
                                            :style="{
                                                height: `calc(${activeLineOffset(nav.children)}px)`,
                                            }"
                                            class="absolute -top-[6px] left-[14.75px] w-[1.25px] bg-slate-800/50 dark:bg-slate-400/40"
                                        />
                                        <ul class="space-y-1">
                                            <template
                                                v-for="(child, cIndex) in nav.children"
                                                :key="cIndex"
                                            >
                                                <!-- A module may draw a thin divider (`divider: true`) or a small heading (`heading: <translation key>`) between its rows. -->
                                                <li
                                                    v-if="child.divider"
                                                    role="separator"
                                                    class="mx-2 my-1 border-t border-slate-800/10 dark:border-slate-100/10"
                                                    data-nav-divider
                                                />
                                                <li
                                                    v-else-if="child.heading"
                                                    role="presentation"
                                                    class="flex h-6 items-end pl-8 pr-2 text-xs font-medium text-slate-500 dark:text-slate-400"
                                                    data-nav-heading
                                                >
                                                    {{ $t(child.heading) }}
                                                </li>
                                                <li
                                                    v-else-if="childShown(child)"
                                                    :class="
                                                        clsx(
                                                            'flex flex-col items-center relative w-full'
                                                        )
                                                    "
                                                >
                                                    <div
                                                        class="flex items-center relative w-full group"
                                                    >
                                                        <!-- Not usable yet (the module says why): greyed out, the reason as tooltip, not a link. -->
                                                        <a-tooltip
                                                            v-if="navDisabled(child)"
                                                            placement="right"
                                                            :title="$t(navDisabled(child))"
                                                        >
                                                            <span
                                                                class="rounded-lg flex items-center pr-2 pl-8 py-1 w-full text-sm space-x-2 text-slate-700/80 opacity-50 cursor-not-allowed dark:text-slate-400"
                                                                aria-disabled="true"
                                                                :data-nav-disabled="child.name"
                                                            >
                                                                <span class="flex-1">
                                                                    {{
                                                                        child.text
                                                                            .map((t) => $t(t))
                                                                            .join(' ')
                                                                    }}
                                                                </span>
                                                                <i-icon
                                                                    v-if="child.icon"
                                                                    :icon="child.icon"
                                                                    class="size-4 text-gray-400"
                                                                />
                                                            </span>
                                                        </a-tooltip>
                                                        <router-link
                                                            v-else
                                                            :class="
                                                                clsx(
                                                                    activeNav(child.name)
                                                                        ? 'bg-white text-slate-900/80 font-medium shadow-xs dark:bg-slate-800 dark:text-slate-100'
                                                                        : 'text-slate-700/80 hover:bg-slate-50/30 hover:text-slate-700 dark:text-slate-300 dark:hover:bg-slate-800/60 dark:hover:text-slate-100',
                                                                    'rounded-lg flex items-center pr-2 pl-8 py-1 w-full',
                                                                    'text-sm space-x-2'
                                                                )
                                                            "
                                                            :to="{ name: child.name }"
                                                        >
                                                            <i-icon
                                                                :class="
                                                                    clsx(
                                                                        activeNav(child.name)
                                                                            ? 'text-slate-950 dark:text-slate-100'
                                                                            : ' hidden group-hover:block'
                                                                    )
                                                                "
                                                                class="absolute z-10 left-[12.1px] top-1/2 size-4 text-gray-400 transform -translate-y-1/2"
                                                                icon="arrow-right-bottom"
                                                            />
                                                            <span class="flex-1">
                                                                {{
                                                                    child.text
                                                                        .map((t) => $t(t))
                                                                        .join(' ')
                                                                }}
                                                            </span>
                                                            <nav-badge :name="child.name" />
                                                            <i-icon
                                                                v-if="child.icon"
                                                                :class="
                                                                    clsx(
                                                                        activeNav(child.name)
                                                                            ? 'text-slate-950 dark:text-slate-100'
                                                                            : ' hidden'
                                                                    )
                                                                "
                                                                :icon="child.icon"
                                                                class="size-4 text-gray-400"
                                                            />
                                                        </router-link>
                                                    </div>
                                                    <div
                                                        v-if="
                                                            child.children &&
                                                            child.children.length > 0 &&
                                                            activeSubGroupNav(child.group)
                                                        "
                                                        class="pt-1 relative w-full pl-4"
                                                    >
                                                        <div class="w-full">
                                                            <div
                                                                :style="{
                                                                    height: `calc(${
                                                                        currentChildActivePosition(
                                                                            child.children
                                                                        ) *
                                                                            32 +
                                                                        10
                                                                    }px)`,
                                                                }"
                                                                class="absolute -top-[6px] left-[30.75px] w-[1.25px] bg-slate-800/50 dark:bg-slate-400/40"
                                                            />
                                                            <ul class="p-0 w-full space-y-1">
                                                                <li
                                                                    v-for="(
                                                                        lv2, cLv2
                                                                    ) in child.children"
                                                                    :key="cLv2"
                                                                >
                                                                    <template v-if="rule(lv2.rule)">
                                                                        <router-link
                                                                            :class="
                                                                                clsx(
                                                                                    activeNav(
                                                                                        lv2.name,
                                                                                        2
                                                                                    )
                                                                                        ? 'bg-white text-slate-900/80 font-medium shadow-xs dark:bg-slate-800 dark:text-slate-100'
                                                                                        : 'text-slate-700/80 hover:bg-slate-50/30 hover:text-slate-700 dark:text-slate-300 dark:hover:bg-slate-800/60 dark:hover:text-slate-100',
                                                                                    'rounded-lg flex items-center pr-2 pl-8 py-1 w-full',
                                                                                    'text-sm space-x-2 relative'
                                                                                )
                                                                            "
                                                                            :to="{ name: lv2.name }"
                                                                        >
                                                                            <i-icon
                                                                                :class="
                                                                                    clsx(
                                                                                        activeNav(
                                                                                            lv2.name,
                                                                                            2
                                                                                        )
                                                                                            ? 'text-slate-950 dark:text-slate-100'
                                                                                            : ' hidden group-hover:block'
                                                                                    )
                                                                                "
                                                                                class="absolute z-10 left-[12.1px] top-1/2 size-4 text-gray-400 transform -translate-y-1/2"
                                                                                icon="arrow-right-bottom"
                                                                            />
                                                                            <span class="flex-1">
                                                                                {{
                                                                                    lv2.text
                                                                                        .map((t) =>
                                                                                            $t(t)
                                                                                        )
                                                                                        .join(' ')
                                                                                }}
                                                                            </span>
                                                                            <i-icon
                                                                                v-if="lv2.icon"
                                                                                :class="
                                                                                    clsx(
                                                                                        activeNav(
                                                                                            lv2.name
                                                                                        )
                                                                                            ? 'text-slate-950 dark:text-slate-100'
                                                                                            : ' hidden'
                                                                                    )
                                                                                "
                                                                                :icon="lv2.icon"
                                                                                class="size-4 text-gray-400"
                                                                            />
                                                                        </router-link>
                                                                    </template>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </li>
                                            </template>
                                        </ul>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </a-layout-sider>
                    <a-layout class="p-3 bg-blk-100/60 dark:bg-slate-900 flex-1 w-full h-full min-h-0 overflow-y-auto">
                        <a-layout-content class="h-full">
                            <div class="min-h-full flex flex-col">
                                <main class="pb-4 dark:bg-slate-900 h-full flex-1">
                                    <router-view />
                                </main>
                            </div>
                        </a-layout-content>
                    </a-layout>
                </a-layout>
            </a-layout>
        </template>
        <a-skeleton v-else-if="siteGate === 'loading'" active class="max-w-md mx-auto mt-8 px-4" />
        <!-- The site backend did not answer the status request: a retry, never a sign-in that cannot work. Hub
             connection itself never blocks sign-in; a site connects later from Modules. -->
        <ConnectHub
            v-else-if="siteGate === 'unavailable'"
            :status="siteStatusRef"
            :unavailable="siteGate === 'unavailable'"
            @connected="onSiteConnected"
            @refresh="refreshSiteStatus"
        />
    </a-config-provider>
</template>

<script setup>
import { demoReadOnly, demoSiteReset } from '@/runtime/demoMode'
import { computed, defineAsyncComponent, onMounted, provide, ref, watch } from 'vue'
import { theme as antDesignTheme } from 'ant-design-vue'
import { antdDarkComponents, antdDarkTokens, antdTheme } from '@/config/antd-theme.js'
import {
    buildVersion,
    CACHE_KEY_ACCOUNT_DATA,
    CACHE_KEY_SIGN_IN_AS_META,
    CACHE_KEY_DOMAIN,
    CACHE_KEY_GLOBAL_SETTINGS,
    CACHE_KEY_LANGUAGES,
    CACHE_KEY_PREFIX,
    cmsTitle,
    cmsVersion,
    cookie,
    COOKIE_KEY_CMS_ADMIN_THEME,
    COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE,
    COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN,
    COOKIE_KEY_LOCALE,
    COOKIE_KEY_TOKEN,
    apiUrl,
    CURRENT_VERSION_KEY,
    logoAuthUrl,
    logoDarkUrl,
} from '@/config'
import clsx from 'clsx'
import { useRoute, useRouter } from 'vue-router'
import { useStorage } from '@vueuse/core'
import { getSettings, signOutAccount } from '@/libs'
import dayjs from 'dayjs'
import localizedFormat from 'dayjs/plugin/localizedFormat'
import { isEmpty } from 'lodash-es'
import axios from 'axios'
import mainNavConfig, { filterWebmasterOnlyNav } from '@/config/navigation.js'
import { adminPostTypes, applyBackendRegistry, registryNavigation } from '@/config/admin-modules'
import NavBadge from '@/components/nav-badge.vue'
import BackgroundTaskBar from '@/components/background-task-bar.vue'
import MyTasksBadge from '@/components/my-tasks-badge.vue'
import NotificationBell from '@/components/notification-bell.vue'
import { iAlert, rule } from '@/mixins'
import { hasAccountData, isWebmaster, isWebmasterRouteBlocked } from '@/hooks/useCan'
import { useAccount, useEnumerates, useLanguages, isModuleActive, fetchAndCacheActiveModules } from '@/hooks'
import { useActiveModules } from '@/hooks/useActiveModules'
import { refreshLegacyEmailHandover } from '@/utils/moduleBundles'
import { resolveOpenNavGroup } from '@/utils/navOpenGroup'
import { announceModuleOff, isRouteModuleBlocked, moduleOffNotice } from '@/utils/moduleRouteGate'
import { useI18n } from 'vue-i18n'
import { useAppRuntime, appRuntime } from '@/runtime'
import { loadSiteStatus, siteGateView } from '@/pages/auth/site-gate'

const AuthSignIn = defineAsyncComponent(() => import('@/pages/auth/sign-in.vue'))
const ConnectHub = defineAsyncComponent(() => import('@/pages/auth/connect-hub.vue'))

// Get locale runtime (singleton)
const {
    locale: globalLocale,
    antLocale,
    toggleLocale: changeLanguage,
    formatLocalizedDateTime,
} = useAppRuntime()

dayjs.extend(localizedFormat)

const { activeModuleCatalogLoaded } = useActiveModules()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const usesPublicShell = computed(() => route.meta?.publicShell === true)
const usesAccountShell = computed(() => route.meta?.accountShell === true)

const filterNavByActiveModules = (items = []) => {
    return items
        .filter((nav) => isModuleActive(nav.module))
        .map((nav) => {
            if (!Array.isArray(nav.children) || nav.children.length === 0) {
                return nav
            }

            return {
                ...nav,
                children: filterNavByActiveModules(nav.children),
            }
        })
}

// Resource-only modules from the backend registry follow the bundle menu (bundle wins for the same key).
const mainNav = computed(() =>
    filterWebmasterOnlyNav(filterNavByActiveModules([...mainNavConfig, ...registryNavigation.value]), isWebmaster())
)
// The sidebar opens the group whose nav children contain the current route, not only the route's own meta.group.
const openNavGroup = computed(() => resolveOpenNavGroup(mainNav.value, route))
// A module may grey a child out until its prerequisite exists: `disabled` is a translation key (the reason, shown as tooltip) or a
// function returning one, read on every render so a function that reads a ref re-evaluates when that ref changes
// (see cms/Webmail/admin/navigation-config.js). '' / false = usable.
// A child can also hide itself: `hidden` is a boolean or a function returning one (read on every render, like `disabled`).
// A top-level entry may use it too (Widget's "Khu vực chung" shows only when the active theme has a shared area).
const navHidden = (item) => (typeof item?.hidden === 'function' ? !!item.hidden() : !!item?.hidden)
// A row is drawn when its permission passes and it is not hidden; dividers and headings are always drawn.
const childShown = (item) => !!item && !item.divider && !item.heading && rule(item.rule) && !navHidden(item)
// Height of the vertical guide line down to the active child: every drawn row is 32px (28px + 4px gap), a divider 13px, a heading 28px.
const ROW_PX = { row: 32, divider: 13, heading: 28 }
const activeLineOffset = (children = []) => {
    let offset = 10
    for (const child of children || []) {
        if (child.divider) offset += ROW_PX.divider
        else if (child.heading) offset += ROW_PX.heading
        else if (childShown(child)) {
            if (child.name === route.name || (child.name && route.meta?.activeNav === child.name)) return offset
            offset += ROW_PX.row
        }
    }
    return -22
}
const navDisabled = (item) => {
    const value = typeof item?.disabled === 'function' ? item.disabled() : item?.disabled
    return typeof value === 'string' ? value : ''
}

const savedTheme = ref(cookie.get(COOKIE_KEY_CMS_ADMIN_THEME, 'light'))
// Ant Design follows the admin theme: the dark algorithm plus the token-file surface/ink values when `.dark` is on.
const antdThemeConfig = computed(() =>
    savedTheme.value === 'dark'
        ? { ...antdTheme, algorithm: antDesignTheme.darkAlgorithm, token: { ...antdTheme.token, ...antdDarkTokens }, components: antdDarkComponents }
        : { ...antdTheme, algorithm: antDesignTheme.defaultAlgorithm }
)
const readyToFetchGlobalData = ref(false)
const sidebarOpen = ref(false)

// formatLocalizedDateTime is now provided from appRuntime
provide('formatLocalizedDateTime', formatLocalizedDateTime)
//  Use for seo-box component
const categoriesList = ref([])
provide('categoriesList', categoriesList)

const currentChildActivePosition = (currentNavs = []) => {
    if (!currentNavs || currentNavs.length === 0) {
        return -1
    }

    // Only the rows that are drawn count, so the active line lands on the right item when a child is not permitted.
    return currentNavs.filter((nav) => rule(nav.rule)).findIndex((nav) => {
        return nav.name === route.name || (nav.name && route.meta?.activeNav === nav.name)
    })
}

//  switchTheme set/remove the .dark class on the html element
const switchTheme = () => {
    const htmlElement = document.documentElement
    if (htmlElement.classList.contains('dark')) {
        htmlElement.classList.remove('dark')
    } else {
        htmlElement.classList.add('dark')
    }

    const newTheme = htmlElement.classList.contains('dark') ? 'dark' : 'light'
    cookie.set(COOKIE_KEY_CMS_ADMIN_THEME, newTheme)
    savedTheme.value = newTheme
}
//  Detect system theme preference and apply it
const detectSystemTheme = () => {
    const savedAdminTheme = cookie.get(COOKIE_KEY_CMS_ADMIN_THEME, '')
    const isDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches
    const htmlElement = document.documentElement
    const isDark = savedAdminTheme ? savedAdminTheme === 'dark' : isDarkMode
    htmlElement.classList.toggle('dark', isDark)
    // Keep the reactive theme in step with the class so the Ant Design algorithm matches the shell.
    savedTheme.value = isDark ? 'dark' : 'light'
}

const activeNav = (name = '', level = 1) => {
    // Detail/create pages declare `meta.activeNav` so the menu item of their section stays highlighted.
    return name === route.name || (!!name && route.meta?.activeNav === name)
}
const activeGroupNav = (groupName = '') => {
    return openNavGroup.value === groupName
}
const activeSubGroupNav = (groupName = '') => {
    return route.meta?.subGroup === groupName
}

const token = cookie.get(COOKIE_KEY_TOKEN)
const impersonationState = useStorage(CACHE_KEY_SIGN_IN_AS_META, {})
const getCookieValue = (key) => {
    const allCookies = cookie.getAll()

    return typeof allCookies[key] === 'undefined' ? '' : allCookies[key]
}
provide('globleToken', token)
provide('globalToken', token) // Correct spelling for new code
// antLocale and globalLocale now come from appRuntime (reactive)
provide('antLocale', antLocale)
provide('globalLocale', globalLocale)
// Hub connection of this site, asked from the site's own backend; the browser holds no domain token.
const siteStatusRef = ref(null)
const siteStatusFailed = ref(false)
const siteGate = computed(() => siteGateView(siteStatusRef.value, siteStatusFailed.value))
provide('siteStatus', siteStatusRef)
const isSameNav = (root, nav) => {
    const explode = nav.split('-')
    explode.length = 1
    return root.includes(explode.join('-'))
}
provide('isSameNav', isSameNav)
const isSameRootNav = (root, nav) => {
    const explode = nav.split('/')
    if (explode.length < 4) {
        return false
    }
    explode.splice(0, 1)
    explode.length = 2
    return explode.join('/').includes(root)
}
provide('isSameRootNav', isSameRootNav)

const globalSettings = useStorage(CACHE_KEY_GLOBAL_SETTINGS, {})
// Init function: Call this once in `main.ts` or app boot
const initGlobalSettings = async () => {
    const currentVersion = localStorage.getItem(CURRENT_VERSION_KEY)

    if (currentVersion !== buildVersion) {
        clearOldCacheKeys()
        localStorage.setItem(CURRENT_VERSION_KEY, buildVersion)
        await fetchAndCacheGlobalSettings()
    } else {
        await loadGlobalSettingsFromLocalStorage()
        // Revalidate cached settings on every boot so fixed backend values
        // replace stale localStorage snapshots after reload.
        await fetchAndCacheGlobalSettings()
    }

    if (isEmpty(languages.value)) {
        languageRefetch()
    }

    await fetchAndCacheActiveModules()
    // Contact -> Email menu: ask once the module list is known whether Webmail receives contact-form mail (not awaited).
    refreshLegacyEmailHandover()
    await applyBackendRegistry(router)
}
provide('globalSettings', globalSettings)
appRuntime.bindGlobalSettings(globalSettings)
provide('initGlobalSettings', initGlobalSettings)
const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const accountAvatarUrl = computed(() => {
    const avatar = accountData.value?.avatar || {}

    return (
        avatar.thumbnailSm ||
        avatar.thumbnail_sm ||
        avatar.url ||
        avatar.path ||
        null
    )
})
const impersonationDisplayName = computed(() => {
    const targetUser = impersonationState.value?.targetUser || {}

    return (
        targetUser.name ||
        targetUser.username ||
        accountData.value?.name ||
        accountData.value?.username ||
        'account'
    )
})
const isImpersonating = computed(() => {
    return Boolean(getCookieValue(COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN))
})
const languages = useStorage(CACHE_KEY_LANGUAGES, [])
appRuntime.bindLanguages(languages)
provide('languages', languages)

const dateTimeType = ref([
    { value: 'last_week', text: 'text.last_week' },
    { value: 'last_month', text: 'text.last_month' },
    { value: 'last_7_days', text: 'text.last_7_days' },
    { value: 'last_30_days', text: 'text.last_30_days' },
    { value: 'dateRange', text: 'text.dateRange' },
])
provide('dateTimeType', dateTimeType)
/** Handle name of key in translate */
const nameHandle = (name) => {
    return name.replace('attributes.', 'a.').replace('between.', 'bw.')
}
provide('nameHandle', nameHandle)

const exitImpersonation = async () => {
    if (!isImpersonating.value) {
        return
    }

    const originalLocale = getCookieValue(COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE)
    const originalToken = getCookieValue(COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN)

    try {
        await signOutAccount()
    } catch (error) {
        console.warn('Failed to sign out impersonated account:', error)
    } finally {
        cookie.remove(COOKIE_KEY_TOKEN)
        cookie.remove(COOKIE_KEY_LOCALE)

        if (originalToken) {
            cookie.set(COOKIE_KEY_TOKEN, originalToken)
        }

        if (originalLocale) {
            cookie.set(COOKIE_KEY_LOCALE, originalLocale)
        }

        cookie.remove(COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN)
        cookie.remove(COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE)
        localStorage.removeItem(CACHE_KEY_ACCOUNT_DATA)
        localStorage.removeItem(CACHE_KEY_SIGN_IN_AS_META)

        window.location.reload()
    }
}

/** Process to sign out */
const signOut = () => {
    signOutAccount().then((res) => {
        if (res.status === 'success') {
            iAlert(res.message)
            //  remove all local storage
            localStorage.clear()

            cookie.remove(COOKIE_KEY_TOKEN)
            cookie.remove(COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN)
            cookie.remove(COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE)
            localStorage.removeItem(CACHE_KEY_SIGN_IN_AS_META)

            setTimeout(() => {
                router.go(0)
            }, 1000)
        }
    })
}
provide('signOut', signOut)
appRuntime.bindSignOut(signOut)

// Removes all iSpa-app-* keys that don’t match current buildVersion
function clearOldCacheKeys() {
    Object.keys(localStorage).forEach((key) => {
        if (
            key.startsWith(CACHE_KEY_PREFIX) &&
            !key.includes(buildVersion) &&
            key !== CURRENT_VERSION_KEY
        ) {
            localStorage.removeItem(key)
        }
    })
}

// Load from localStorage with fallback check
async function loadGlobalSettingsFromLocalStorage() {
    try {
        const value = globalSettings.value
        if (value && Object.keys(value).length > 0) {
            return true
        }
    } catch (error) {
        console.warn('Error reading from localStorage:', error)
    }
    return false
}

// Load from API and save to localStorage
async function fetchAndCacheGlobalSettings() {
    try {
        globalSettings.value = await getSettings()
    } catch (error) {
        console.error('Failed to fetch global settings:', error)
    }
}

provide('fetchAndCacheGlobalSettings', fetchAndCacheGlobalSettings)
appRuntime.bindFetchAndCacheGlobalSettings(fetchAndCacheGlobalSettings)

const typeOption = [
    {
        text: t('text.tab.basic'),
        options: [
            { value: 'text', text: t('enum.text') },
            { value: 'textarea', text: t('enum.textarea') },
            { value: 'number', text: t('enum.number') },
            { value: 'email', text: t('enum.email') },
            { value: 'password', text: t('enum.password') },
            { value: 'date', text: t('enum.date') },
            { value: 'datetime', text: t('enum.datetime') },
        ],
    },
    {
        text: t('text.select'),
        options: [
            { value: 'select', text: t('enum.select') },
            { value: 'checkbox', text: t('enum.checkbox') },
            { value: 'radio', text: t('enum.radio') },
        ],
    },
    {
        text: t('text.tab.content'),
        options: [
            { value: 'image', text: t('enum.image') },
            { value: 'document', text: t('enum.document') },
            { value: 'file', text: t('enum.file') },
            { value: 'editor', text: t('enum.editor') },
        ],
    },
    {
        text: t('text.tab.advanced'),
        options: [
            { value: 'button', text: t('enum.button') },
            { value: 'reset', text: t('enum.reset') },
            { value: 'submit', text: t('enum.submit') },
            { value: 'color', text: t('enum.color') },
            { value: 'search', text: t('enum.search') },
            { value: 'range', text: t('enum.range') },
            { value: 'time', text: t('enum.time') },
            { value: 'month', text: t('enum.month') },
            { value: 'week', text: t('enum.week') },
            { value: 'tel', text: t('enum.tel') },
            { value: 'url', text: t('enum.url') },
        ],
    },
]
provide('typeOption', typeOption)
// Ask the site backend whether this site is connected to the hub
const refreshSiteStatus = async () => {
    siteStatusFailed.value = false
    siteStatusRef.value = null
    const answer = await loadSiteStatus(axios, apiUrl)
    siteStatusFailed.value = answer === null
    siteStatusRef.value = answer
}
// A successful connect: ask again, then continue as a connected site
const onSiteConnected = async () => {
    await refreshSiteStatus()
    if (siteGate.value === 'ready' && !isEmpty(token)) {
        await initGlobalSettings()
    }
}

function clearUserStorageAndCookies() {
    // Clear all iSpa-app-* keys in localStorage
    Object.keys(localStorage).forEach((key) => {
        if (key.startsWith(CACHE_KEY_PREFIX)) {
            localStorage.removeItem(key)
        }
    })

    // Remove version key as well
    localStorage.removeItem(CURRENT_VERSION_KEY)

    // Remove cookies
    cookie.remove(COOKIE_KEY_TOKEN)
    cookie.remove(COOKIE_KEY_LOCALE)
    cookie.remove(COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN)
    cookie.remove(COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE)
    cookie.remove(CACHE_KEY_DOMAIN)
    localStorage.removeItem(CACHE_KEY_SIGN_IN_AS_META)
}

//  Get account info
const { data: accountResponse } = useAccount({
    enabled: !isEmpty(token),
})
//  Get languages if empty
const { data: languagesResponse, refetch: languageRefetch } = useLanguages(
    {},
    {
        enabled: readyToFetchGlobalData.value,
    }
)
provide('languageRefetch', languageRefetch)

const status = ref([])
provide('status', status)
const { data: enumerateData } = useEnumerates(
    {},
    {
        enabled: readyToFetchGlobalData.value,
    }
)

const postMedia = () => {
    const arr = [
        {
            value: 'post',
            label: `${t('text.type.post')} (post)`,
            prefix: { en: 'blogs', vi: 'bai-viet' },
        },
    ]

    if (rule('post.video')) {
        arr.push({
            value: 'video',
            label: `${t('text.type.video')} (video)`,
            prefix: { en: 'video', vi: 'video' },
        })
    }
    if (rule('post.audio')) {
        arr.push({
            value: 'audio',
            label: `${t('text.type.audio')} (audio)`,
            prefix: { en: 'audio', vi: 'audio' },
        })
    }
    if (rule('post.document')) {
        arr.push({
            value: 'document',
            label: `${t('text.type.document')} (document)`,
            prefix: { en: 'document', vi: 'tai-lieu' },
        })
    }
    if (rule('post.notification')) {
        arr.push({
            value: 'notification',
            label: `${t('text.type.notification')} (notification)`,
            prefix: { en: 'notifications', vi: 'thong-bao' },
        })
    }
    if (rule('post.admission')) {
        arr.push({
            value: 'admission',
            label: `${t('text.type.admission')} (admission)`,
            prefix: { en: 'news', vi: 'bai-viet' },
        })
    }
    if (rule('post.events')) {
        arr.push({
            value: 'events',
            label: `${t('text.type.events')} (events)`,
            prefix: { en: 'events', vi: 'su-kien' },
        })
    }
    // Post types a module adds (declared in the module's admin definition, never named here).
    for (const type of adminPostTypes) {
        if (rule(type.rule)) {
            arr.push({
                value: type.value,
                label: `${t(type.labelKey)} (${type.value})`,
                prefix: type.prefix,
            })
        }
    }
    if (rule('post.viewAny')) {
        arr.push({
            value: 'page',
            label: `${t('text.type.page')} (pages)`,
            prefix: { en: 'page', vi: 'trang' },
        })
    }

    return arr
}
provide('postMedia', postMedia)

//  Verify domain and get token
onMounted(async () => {
    detectSystemTheme()

    // The old verify answer (domain token) was kept in a cookie; it is not used any more, so drop it.
    cookie.remove(CACHE_KEY_DOMAIN)

    await refreshSiteStatus()
    if (siteGate.value === 'ready' && !isEmpty(token)) {
        await initGlobalSettings()
    }
})

// changeLanguage is now toggleLocale from appRuntime
provide('changeLanguage', changeLanguage)

watch(
    () => route.name,
    (val, old) => {
        if (val !== old) {
            sidebarOpen.value = false
        }
    },
    { immediate: true }
)
// The router guard lets a deep link through while the account is still loading; enforce the route rule once the
// permission index has the account (it updates a tick after accountData is written).
watch(
    () => hasAccountData(),
    (ready) => {
        if (ready && ((route.meta?.rule && !rule(route.meta.rule)) || isWebmasterRouteBlocked(route.meta))) {
            router.replace({ name: 'dashboard' })
        }
    }
)
// Same for a screen of a module that is off: the guard stays open until the module catalog has loaded, then this
// sends a cold deep link to the dashboard.
watch(activeModuleCatalogLoaded, (loaded) => {
    if (isRouteModuleBlocked(route.meta?.module, loaded, isModuleActive)) {
        announceModuleOff()
        router.replace({ name: 'dashboard' })
    }
})
watch(moduleOffNotice, () => {
    iAlert({ message: t('text.module-inactive-redirect'), type: 'warning' })
})
watch(
    () => accountResponse.value,
    (val) => {
        if (val) {
            readyToFetchGlobalData.value = true
            accountData.value = val.data || {}
            languageRefetch()
        }
    },
    { immediate: true, deep: true }
)
watch(
    () => languagesResponse.value,
    (val) => {
        if (val) {
            languages.value = val.data || {}
            localStorage.setItem(CACHE_KEY_LANGUAGES, JSON.stringify(languages.value))
        }
    },
    { immediate: true, deep: true }
)
watch(
    () => enumerateData.value,
    (val) => {
        if (val) {
            status.value = Array.isArray(val) ? val : val.data || []
        }
    },
    { immediate: true, deep: true }
)
</script>
