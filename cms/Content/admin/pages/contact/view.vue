<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs
        :items="[
            {
                name: 'contact-dashboard',
                title: $t('nav.a.contacts'),
                icon: 'email-outline',
            },
            {
                name: 'contact-manage',
                title: $t('nav.a.contact-manage'),
                icon: 'card-account-mail',
            },
            {
                text: $t('nav.b.view-detail'),
                title: $t('nav.b.view-detail'),
                icon: 'eye',
            },
        ]"
    >
        <div class="ml-auto flex items-center space-x-2">
            <router-link
                v-if="rule('contact.update') && contact.id"
                :to="{ name: 'contact-update', params: { id: contact.id } }"
                class="text-gray-800 rounded-sm text-xs bg-blk-200/60 hover:bg-blk-300/80 transition-all px-2 py-1.5 inline-flex items-center space-x-1"
            >
                <i-icon icon="pencil" class="size-4" />
                <span>{{ $t('nav.b.update') }}</span>
            </router-link>

            <a-popconfirm
                v-if="rule('contact.delete') && contact.id"
                :cancel-text="$t('nav.b.cancel')"
                :ok-text="$t('nav.b.delete')"
                :title="$t('text.del-confirm')"
                ok-type="danger"
                @confirm="delContact"
            >
                <button
                    class="text-rose-700 rounded-sm text-xs bg-rose-100 hover:bg-rose-200 transition-all px-2 py-1.5 inline-flex items-center space-x-1"
                >
                    <i-icon icon="delete" class="size-4" />
                    <span>{{ $t('nav.b.delete') }}</span>
                </button>
            </a-popconfirm>
        </div>
    </breadcrumbs>

    <div class="i-box" v-if="rule('contact.viewAny')">
        <a-spin :indicator="loadingSpinner()" :spinning="isLoading">
            <div class="bg-white rounded-lg">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex items-start space-x-4">
                        <div
                            class="size-14 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xl font-semibold shrink-0"
                        >
                            {{ contactInitials }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <h2 class="text-xl font-semibold text-gray-800 m-0">
                                {{ contact.name || $t('text.contact_dashboard.unknown') }}
                            </h2>
                            <p class="text-sm text-gray-500 m-0 mt-1">
                                <span v-if="contact.organization">{{ contact.organization }} · </span>
                                <span v-if="contact.type">
                                    <a-tag color="blue" class="mr-1">{{ contact.type }}</a-tag>
                                </span>
                                <span v-if="contact.source_label">
                                    <a-tag color="green">{{ contact.source_label }}</a-tag>
                                </span>
                                <span v-if="contact.showroom_context?.name">
                                    <a-tag color="cyan">{{ contact.showroom_context.name }}</a-tag>
                                </span>
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <span
                                v-if="contact.is_subscribe"
                                class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700"
                            >
                                {{ $t('text.subscribed') }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-0 divide-y md:divide-y-0 md:divide-x divide-gray-100">
                    <div class="p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wide m-0">
                            {{ $t('text.s.contact') }}
                        </h3>

                        <div class="space-y-3">
                            <div class="flex items-center space-x-3">
                                <i-icon icon="email-outline" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.email') }}</p>
                                    <p class="text-sm text-gray-800 m-0">{{ contact.email || '—' }}</p>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <i-icon icon="phone" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.mobile') }}</p>
                                    <p class="text-sm text-gray-800 m-0">{{ contact.mobile || '—' }}</p>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <i-icon icon="map-marker" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.address') }}</p>
                                    <p class="text-sm text-gray-800 m-0">{{ contact.address || '—' }}</p>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <i-icon icon="web" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.url') }}</p>
                                    <p class="text-sm text-gray-800 m-0">{{ contact.url || '—' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wide m-0">
                            {{ $t('text.details') }}
                        </h3>

                        <div class="space-y-3">
                            <div class="flex items-center space-x-3">
                                <i-icon icon="domain" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.organization') }}</p>
                                    <p class="text-sm text-gray-800 m-0">{{ contact.organization || '—' }}</p>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <i-icon icon="cake-variant" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.birthday') }}</p>
                                    <p class="text-sm text-gray-800 m-0">
                                        {{ contact.birthday ? formatDate(contact.birthday) : '—' }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <i-icon icon="account" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.gender') }}</p>
                                    <p class="text-sm text-gray-800 m-0">{{ genderLabel }}</p>
                                </div>
                            </div>

                            <div class="flex items-center space-x-3">
                                <i-icon icon="clock-outline" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('text.created_at') }}</p>
                                    <p class="text-sm text-gray-800 m-0">
                                        {{ contact.created_at ? formatDate(contact.created_at) : '—' }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="contact.status" class="flex items-center space-x-3">
                                <i-icon icon="timeline-check-outline" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">{{ $t('validation.attributes.status') }}</p>
                                    <p class="text-sm text-gray-800 m-0">{{ statusLabel }}</p>
                                </div>
                            </div>

                            <div v-if="contact.showroom_context" class="flex items-center space-x-3">
                                <i-icon icon="storefront-outline" class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-xs text-gray-500 m-0">Showroom</p>
                                    <p class="text-sm text-gray-800 m-0">{{ contact.showroom_context.name }}</p>
                                    <p class="text-xs text-gray-500 m-0">
                                        {{ contact.showroom_context.address || contact.showroom_context.uid || '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="contact.subject || contact.message_excerpt"
                    class="border-t border-gray-100 px-6 py-5 space-y-3"
                >
                    <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wide m-0">
                        {{ $t('text.contact.latest_inquiry') }}
                    </h3>
                    <p v-if="contact.subject" class="text-sm font-medium text-gray-800 m-0">
                        {{ contact.subject }}
                    </p>
                    <p v-if="contact.message_excerpt" class="text-sm leading-6 text-gray-600 m-0">
                        {{ contact.message_excerpt }}
                    </p>
                </div>
            </div>
        </a-spin>
    </div>
    <no-permission-error v-else />
</template>

<script setup>
import { computed, defineComponent, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { iAlert, loadingSpinner, rule } from '@/mixins'
import { deleteContact, getContact } from '@/libs'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'

defineComponent({
    name: 'ContactView',
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const isLoading = ref(false)
const contact = ref({})

const loadContact = async () => {
    isLoading.value = true
    try {
        const data = await getContact(route.params.id)
        contact.value = data || {}
    } catch (err) {
        iAlert(err, true)
    } finally {
        isLoading.value = false
    }
}

const delContact = async () => {
    try {
        const res = await deleteContact(contact.value.id)
        if (res.data.message) {
            iAlert(res.data)
        }
        if (res.data.status === 'success') {
            router.push({ name: 'contact-manage' })
        }
    } catch (err) {
        iAlert(err, true)
    }
}

const contactInitials = computed(() => {
    const name = contact.value.name || ''
    const parts = name.trim().split(/\s+/)
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    }
    return (name[0] || '?').toUpperCase()
})

const genderLabel = computed(() => {
    const map = {
        0: t('text.gender.unknown'),
        1: t('text.gender.male'),
        2: t('text.gender.female'),
    }
    return map[contact.value.gender] || map[0]
})

const statusLabel = computed(() => {
    const map = {
        replied: t('text.contact_dashboard.status_replied'),
        assigned: t('text.contact_dashboard.status_assigned'),
        new: t('text.contact.status_new'),
        pending: t('text.contact.status_pending'),
    }

    return map[contact.value.status] || contact.value.status || '—'
})

const formatDate = (value) => {
    if (!value) return '—'
    return dayjs(value).format('DD MMM YYYY')
}

onMounted(() => {
    loadContact()
})
</script>
