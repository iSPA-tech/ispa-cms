<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-6">
        <a-form
            id="accountForm"
            :model="form"
            layout="vertical"
            @finish="submit"
            @finish-failed="finishFailed"
        >
            <div class="grid gap-6 xl:grid-cols-[320px_minmax(0,1fr)]">
                <i-box>
                    <div class="flex flex-col items-center text-center">
                        <a-avatar
                            v-if="previewAvatarUrl"
                            :size="96"
                            :src="previewAvatarUrl"
                        />
                        <span
                            v-else
                            class="inline-flex size-24 items-center justify-center rounded-full bg-cyan-50 text-cyan-600 text-6xl"
                        >
                            <i-icon icon="account" />
                        </span>
                        <h2 class="mt-4 text-lg font-semibold text-slate-900">
                            {{ form.name || account.name || $t('text.acc.account-center') }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            @{{ form.username || account.username || $t('validation.attributes.username') }}
                        </p>
                        <p class="mt-3 text-sm text-slate-500">
                            {{ $t('text.profile.update.identity_hint') }}
                        </p>
                    </div>

                    <div class="mt-6">
                        <media-selection
                            v-model="form.avatar"
                            :label="$t('text.tab.avatar')"
                            :limit="1"
                            folder="User"
                            type="avatar"
                        />
                    </div>
                </i-box>

                <div class="space-y-6">
                    <i-box>
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">
                                {{ $t('text.s.basic') }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $t('text.profile.update.fields_hint') }}
                            </p>
                        </div>

                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <a-form-item
                                :label="$t('validation.attributes.fullname')"
                                :rules="[
                                    {
                                        required: true,
                                        trigger: 'blur',
                                        message: $t('validation.required', { a: $t('validation.attributes.name') }),
                                    },
                                ]"
                                name="name"
                            >
                                <a-input
                                    v-model:value="form.name"
                                    :placeholder="$t('validation.attributes.fullname')"
                                    type="text"
                                >
                                    <template #prefix>
                                        <div class="flex items-center">
                                            <i-icon icon="card-account-details" class="size-4" />
                                        </div>
                                    </template>
                                </a-input>
                            </a-form-item>

                            <a-form-item
                                :label="$t('validation.attributes.username')"
                                :rules="[
                                    {
                                        required: true,
                                        trigger: 'blur',
                                        message: $t('validation.required', { a: $t('validation.attributes.username') }),
                                    },
                                ]"
                                name="username"
                            >
                                <a-input
                                    v-model:value="form.username"
                                    :disabled="!rule('user.username')"
                                    :placeholder="$t('validation.attributes.username')"
                                >
                                    <template #prefix>
                                        <div class="flex items-center">
                                            <i-icon icon="fingerprint" class="size-4" />
                                        </div>
                                    </template>
                                </a-input>
                            </a-form-item>

                            <a-form-item
                                :label="$t('validation.attributes.email')"
                                :rules="[
                                    {
                                        required: true,
                                        trigger: 'blur',
                                        message: $t('validation.required', { a: $t('validation.attributes.email') }),
                                    },
                                    {
                                        type: 'email',
                                        trigger: 'blur',
                                        message: $t('validation.email', { a: $t('validation.attributes.email') }),
                                    },
                                ]"
                                name="email"
                            >
                                <a-input
                                    v-model:value="form.email"
                                    :disabled="!rule('user.email')"
                                    :placeholder="$t('validation.attributes.email')"
                                    type="email"
                                >
                                    <template #prefix>
                                        <div class="flex items-center">
                                            <i-icon icon="email" class="size-4" />
                                        </div>
                                    </template>
                                </a-input>
                            </a-form-item>

                            <a-form-item
                                :label="$t('validation.attributes.mobile')"
                                name="mobile"
                            >
                                <a-input
                                    v-model:value="form.mobile"
                                    :disabled="!rule('user.mobile')"
                                    :placeholder="$t('validation.attributes.mobile')"
                                >
                                    <template #prefix>
                                        <div class="flex items-center">
                                            <i-icon icon="cellphone" class="size-4" />
                                        </div>
                                    </template>
                                </a-input>
                            </a-form-item>
                        </div>
                    </i-box>

                    <i-box v-if="metaGroups.length > 0">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">
                                {{ $t('text.acc.additional-profile-details') }}
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $t('text.acc.additional-profile-details-desc') }}
                            </p>
                        </div>

                        <div class="mt-6 grid gap-6 lg:grid-cols-2">
                            <div
                                v-for="meta in metaGroups"
                                :key="meta.id"
                                class="rounded-2xl border border-slate-100 p-4"
                            >
                                <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">
                                    {{ meta.name }}
                                </h3>

                                <div class="mt-4 space-y-4">
                                    <template
                                        v-for="attribute in meta.options"
                                        :key="attribute.id"
                                    >
                                        <meta-attribute-input
                                            v-if="form.meta[attribute.name]"
                                            v-model="form.meta[attribute.name].value"
                                            :attribute="attribute"
                                            :name="['meta', attribute.name, 'value']"
                                        />
                                    </template>
                                </div>
                            </div>
                        </div>
                    </i-box>
                </div>
            </div>
        </a-form>
    </div>
</template>

<script setup>
import { computed, defineComponent, inject, nextTick, onMounted, ref } from 'vue'
import { useStorage } from '@vueuse/core'
import ajax from '@/ajax'
import { getAccount } from '@/libs'
import { finishFailed, iAlert, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'
import { resolveAccountAvatarUrl } from './account-helpers'

defineComponent({
    name: 'AccountUpdate',
})

const account = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const attributes = inject('meta_attributes', ref([]))
const initFormMeta = inject('initFormMeta')
const form = inject('form')
const btnState = inject('btnState', ref(false))

const previewAvatarUrl = computed(() =>
    resolveAccountAvatarUrl({
        avatar: Array.isArray(form.value?.avatar) ? form.value.avatar[0] : null,
    })
)
const metaGroups = computed(() => {
    return (attributes.value || [])
        .map((meta) => ({
            ...meta,
            options: (meta.options || []).filter((attribute) => attribute.is_filter),
        }))
        .filter((meta) => meta.options.length > 0)
})

function submit(data = {}) {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    if (btnState.value) {
        return
    }
    btnState.value = true
    ajax.put('account', value)
        .then(async (res) => {
            if (res.data.status === 'success') {
                const currentAccount = await getAccount()
                account.value = currentAccount.data || {}
                await parseData()
            }

            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

async function parseData() {
    const currentAccount = account.value || {}
    const currentMeta = currentAccount.currentMeta || currentAccount.current_meta || {}
    const normalizedMeta = Object.entries(currentMeta).reduce((result, [key, value]) => {
        const suffix = value?.id ? `_${value.id}` : ''
        const normalizedKey =
            suffix && key.endsWith(suffix) ? key.slice(0, suffix.length * -1) : key

        result[normalizedKey] = value
        return result
    }, {})

    form.value.name = currentAccount.name || null
    form.value.email = currentAccount.email || null
    form.value.username = currentAccount.username || null
    form.value.mobile = currentAccount.mobile || null
    form.value.avatar = currentAccount.avatar ? [currentAccount.avatar] : []
    form.value.meta = Object.assign({}, form.value.meta, normalizedMeta)
}

onMounted(async () => {
    await parseData()

    await nextTick(() => {
        initFormMeta()
    })
})
</script>
