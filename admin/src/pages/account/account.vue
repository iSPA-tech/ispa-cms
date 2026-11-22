<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex-1 xl:overflow-y-auto bg-bg">
        <div class="w-full">
            <breadcrumbs
                :items="[
                    {
                        icon: 'account',
                        title: t('text.acc.account-center'),
                        text: t('text.acc.account-center'),
                    },
                ]"
                class="bg-surface px-4 pt-3 sm:px-6 lg:px-8"
            >
                <!-- Every signed-in admin edits their own profile; user.update is about other users. -->
                <template v-if="route.name === 'account-update'">
                    <i-button :to="{ name: 'account-dashboard' }" as="router-link" variant="default">
                        <template #icon>
                            <i-icon class="size-3.5" icon="arrow-left" />
                        </template>
                        <span>{{ t('nav.b.back') }}</span>
                    </i-button>
                    <i-button
                        :disabled="btnState"
                        :loading="btnState"
                        form="accountForm"
                        native-type="submit"
                        variant="primary"
                    >
                        <template #icon>
                            <i-icon class="size-3.5" icon="content-save" />
                        </template>
                        <span>{{ t('nav.b.save') }}</span>
                    </i-button>
                </template>
            </breadcrumbs>
            <div class="border-b border-border bg-surface px-4 py-6 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                    <div class="flex min-w-0 items-start gap-4">
                        <span class="inline-flex shrink-0 items-center justify-center">
                            <a-avatar v-if="accountAvatarUrl" :size="72" :src="accountAvatarUrl" />
                            <span
                                v-else
                                class="inline-flex size-[72px] items-center justify-center rounded-full bg-primary-soft text-primary-ink text-5xl"
                            >
                                <i-icon icon="account" />
                            </span>
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-3">
                                <h1 class="truncate text-2xl font-semibold text-ink">
                                    {{ accountData.name || t('text.acc.account-center') }}
                                </h1>
                                <span
                                    :class="
                                        accountData.emailVerifiedAt
                                            ? 'bg-success-soft text-success-ink'
                                            : 'bg-warning-soft text-warning-ink'
                                    "
                                    class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium"
                                >
                                    {{
                                        accountData.emailVerifiedAt
                                            ? t('text.acc.email-verified')
                                            : t('text.acc.email-needs-verification')
                                    }}
                                </span>
                            </div>

                            <p class="mt-2 max-w-3xl text-sm text-ink-muted">
                                {{ routeDescription }}
                            </p>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <app-stat-pill
                                    icon="fingerprint"
                                    :label="t('validation.attributes.username')"
                                    :value="accountData.username || t('text.acc.not-set')"
                                />
                                <app-stat-pill
                                    icon="email"
                                    :label="t('text.auth.email')"
                                    :value="accountData.email || t('text.acc.not-set')"
                                />
                                <app-stat-pill
                                    icon="calendar-multiple"
                                    :label="t('text.acc.last-access')"
                                    :value="lastAccessLabel"
                                    variant="muted"
                                />
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                                <span
                                    v-for="(role, ind) in accountData.roles || []"
                                    :key="ind"
                                    class="inline-flex items-center rounded-full border border-border bg-surface-sunken px-3 py-1 font-medium text-ink-muted"
                                >
                                    {{ role.name }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <router-link :to="{ name: 'account-update' }">
                            <a-button>
                                <span class="inline-flex items-center gap-2">
                                    <i-icon icon="pencil" class="size-4" />
                                    <span>{{ t('text.acc.edit-profile') }}</span>
                                </span>
                            </a-button>
                        </router-link>
                        <router-link :to="{ name: 'account-setting' }">
                            <a-button>
                                <span class="inline-flex items-center gap-2">
                                    <i-icon icon="cog" class="size-4" />
                                    <span>{{ t('text.acc.preferences') }}</span>
                                </span>
                            </a-button>
                        </router-link>
                        <router-link :to="{ name: 'account-password' }">
                            <a-button danger type="primary">
                                <span class="inline-flex items-center gap-2">
                                    <i-icon icon="lock" class="size-4" />
                                    <span>{{ t('text.acc.password-security') }}</span>
                                </span>
                            </a-button>
                        </router-link>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <router-link
                        v-for="item in navigationItems"
                        :key="item.name"
                        :to="{ name: item.name }"
                    >
                        <button
                            :class="
                                route.name === item.name
                                    ? 'border-border bg-primary-soft text-primary-ink'
                                    : 'border-border bg-surface text-ink-muted hover:border-border hover:text-ink'
                            "
                            class="inline-flex items-center rounded-full border px-4 py-2 text-sm font-medium transition"
                            type="button"
                        >
                            {{ item.label }}
                        </button>
                    </router-link>
                </div>
            </div>

            <div class="px-4 py-6 pb-12 sm:px-6 lg:px-8">
                <router-view></router-view>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, nextTick, provide, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { isEmpty } from 'lodash-es'
import { useMetaClasses } from '@/hooks'
import { can } from '@/hooks/useCan'
import { useStorage } from '@vueuse/core'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'
import { dateFormat } from '@/mixins'
import { resolveAccountAvatarUrl } from './account-helpers'

defineComponent({
    name: 'Account',
})
const { t } = useI18n()
const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const route = useRoute()
const btnState = ref(false)
provide('btnState', btnState)
const navigationItems = computed(() => [
    {
        name: 'account-dashboard',
        label: t('web.overview_title'),
    },
    {
        name: 'account-activity',
        label: t('text.acc.activity-history'),
    },
    {
        name: 'account-update',
        label: t('text.acc.edit-profile'),
    },
    {
        name: 'account-setting',
        label: t('text.acc.preferences'),
    },
    {
        name: 'account-password',
        label: t('text.acc.password-security'),
    },
])
const routeDescriptions = computed(() => ({
    'account-dashboard': t('text.acc.dashboard-desc'),
    'account-activity': t('text.acc.activity-history-desc'),
    'account-update': t('text.acc.update-profile-desc'),
    'account-setting': t('text.acc.preferences-route-desc'),
    'account-password': t('text.acc.password-route-desc'),
}))
const accountAvatarUrl = computed(() => resolveAccountAvatarUrl(accountData.value))
const routeDescription = computed(() => {
    return routeDescriptions.value[route.name] || routeDescriptions.value['account-dashboard']
})
const lastAccessLabel = computed(() => {
    if (accountData.value?.lastLogin?.createdAt) {
        return dateFormat(accountData.value.lastLogin.createdAt, 'YYYY-MM-DD HH:mm')
    }

    if (accountData.value?.createdAt) {
        return dateFormat(accountData.value.createdAt, 'YYYY-MM-DD')
    }

    return t('text.acc.no-access-history')
})

const formDefault = () => {
    return {
        name: null,
        email: null,
        username: null,
        mobile: null,
        avatar: [],
        meta: {},
    }
}
const form = ref(formDefault())
provide('form', form)

const meta_attributes = ref([])
provide('meta_attributes', meta_attributes)
//  Get meta attributes
useMetaClasses(
    {
        target: 'User',
    },
    {
        // A role that may not list meta classes (the read-only demo role) never sends the request: no permission error.
        enabled: can('meta_class', 'viewAny'),
        onSuccess: (res) => {
            meta_attributes.value = res.data || []
            initFormMeta()
        },
    }
)
const initFormMeta = async () => {
    const newFormMeta = { ...form.value.meta }

    await nextTick(() => {
        meta_attributes.value.forEach((classObj) => {
            if (!isEmpty(classObj.options)) {
                classObj.options
                    .filter((item) => item.is_filter)
                    .forEach((option) => {
                        if (newFormMeta[option.name] === undefined) {
                            newFormMeta[option.name] = {
                                value: null,
                            }
                        }
                        if (
                            isEmpty(newFormMeta[option.name]) ||
                            !newFormMeta[option.name].hasOwnProperty('id') ||
                            newFormMeta[option.name].attr_id !== option.id
                        ) {
                            newFormMeta[option.name] = {
                                meta_id: 0,
                                class_id: classObj.id,
                                type: option.type,
                                attr_id: option.id,
                                value: option.input_type === 'select' ? [] : null,
                            }
                        }
                    })
            }

            form.value.meta = newFormMeta
        })
    })
}
provide('initFormMeta', initFormMeta)
</script>
