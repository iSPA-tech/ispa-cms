<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="mx-auto max-w-6xl space-y-4">
        <breadcrumbs
            :items="[
                {
                    name: 'user-dashboard',
                    icon: 'account-group',
                    title: $t('nav.a.users'),
                },
                {
                    text: $t('nav.b.update'),
                    title: $t('nav.b.update'),
                },
            ]"
        >
            <i-button :to="{ name: 'user-manage' }" as="router-link" variant="default">
                <template #icon>
                    <i-icon class="size-3.5" icon="arrow-left" />
                </template>
                <span>{{ $t('nav.b.back') }}</span>
            </i-button>
            <i-button
                :disabled="btnState"
                :loading="btnState"
                form="user-form"
                native-type="submit"
                variant="primary"
            >
                <template #icon>
                    <i-icon class="size-3.5" icon="content-save" />
                </template>
                <span>{{ $t('nav.b.save') }}</span>
            </i-button>
        </breadcrumbs>

        <div v-if="rule('user.update')">
            <a-form id="user-form" :model="form" layout="vertical" @finish="userSubmit">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="lg:col-span-2 space-y-3">
                        <i-box class="space-y-4">
                            <h3 class="text-sm font-semibold uppercase text-slate-700">
                                {{ $t('text.s.basic') }}
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <a-form-item
                                    :label="$t('validation.attributes.fullname')"
                                    :rules="[
                                        {
                                            required: true,
                                            trigger: 'blur',
                                            message: $t('validation.required', {
                                                a: $t('validation.attributes.fullname'),
                                            }),
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
                                                <i-icon icon="id-card" class="size-4" />
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
                                            message: $t('validation.required', {
                                                a: $t('validation.attributes.username'),
                                            }),
                                        },
                                    ]"
                                    name="username"
                                >
                                    <a-input
                                        v-model:value="form.username"
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
                                            message: $t('validation.required', {
                                                a: $t('validation.attributes.email'),
                                            }),
                                        },
                                        {
                                            type: 'email',
                                            trigger: 'blur',
                                            message: $t('validation.email', {
                                                a: $t('validation.attributes.email'),
                                            }),
                                        },
                                    ]"
                                    name="email"
                                >
                                    <a-input
                                        v-model:value="form.email"
                                        :placeholder="$t('validation.attributes.email')"
                                        type="email"
                                    >
                                        <template #prefix>
                                            <div class="flex items-center">
                                                <i-icon class="size-4" icon="email" />
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

                        <div v-if="(attributesData || []).length" class="space-y-3">
                            <template v-for="meta in attributesData || []" :key="meta.id">
                                <i-box v-if="meta && meta.id" class="space-y-4">
                                    <h3 class="text-sm font-semibold uppercase text-slate-700">
                                        {{ meta.name }}
                                    </h3>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <meta-attribute-input
                                            v-for="attribute in meta.options"
                                            :key="attribute.id"
                                            v-if="attribute && form.meta[attribute.name]"
                                            v-model="form.meta[attribute.name].value"
                                            :attribute="attribute"
                                            :name="['meta', attribute.name, 'value']"
                                        />
                                    </div>
                                </i-box>
                            </template>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <i-box class="space-y-4">
                            <h3 class="text-sm font-semibold uppercase text-slate-700">
                                {{ $t('text.config.title').replace(':name', $t('nav.a.users')) }}
                            </h3>

                            <div class="space-y-3">
                                <media-selection
                                    v-model="form.avatar"
                                    :limit="1"
                                    folder="User"
                                    type="avatar"
                                />

                                <select-input-user-role
                                    v-if="rule('user.role')"
                                    v-model="form.roles"
                                    :label="$t('nav.a.roles')"
                                    class="border-0"
                                    multiple
                                    name="roles"
                                />

                                <a-form-item
                                    v-if="rule('user.type')"
                                    :label="$t('text.u.type')"
                                    name="type"
                                >
                                    <a-select
                                        v-model:value="form.type"
                                        :placeholder="$t('text.select')"
                                    >
                                        <a-select-option :value="0">{{
                                            $t('text.user.type_admin')
                                        }}</a-select-option>
                                        <a-select-option :value="1">
                                            {{ $t('text.user.type_organization') }}
                                        </a-select-option>
                                        <a-select-option :value="2">{{
                                            $t('text.user.type_personal')
                                        }}</a-select-option>
                                        <a-select-option :value="3">{{ $t('text.user.type_guest') }}</a-select-option>
                                    </a-select>
                                </a-form-item>

                                <a-form-item
                                    v-if="accountData.id !== form.id"
                                    :label="$t('validation.attributes.status')"
                                >
                                    <a-radio-group
                                        v-model:value="form.status"
                                        class="flex flex-col gap-2"
                                    >
                                        <a-radio
                                            v-for="(item, ind) in (statuses || []).filter(
                                                (s) => s.type === 'user_status'
                                            )"
                                            :key="ind"
                                            :value="item.enum_key"
                                        >
                                            {{ isVi ? item.name : item.name_alias }}
                                        </a-radio>
                                    </a-radio-group>
                                </a-form-item>
                            </div>
                        </i-box>
                    </div>
                </div>
            </a-form>
        </div>
        <no-permission-error v-else />
    </div>
</template>

<script setup>
import { computed, defineComponent, inject, nextTick, onMounted, watch } from 'vue'
import { objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { useStorage } from '@vueuse/core'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'
import { useEnumerates, useMetaClasses } from '@/hooks'
import { useRoute } from 'vue-router'
import { getUser } from '@/libs'
import dayjs from 'dayjs'
import { useAppRuntime } from '@/runtime'

defineComponent({
    name: 'UserUpdate',
})

const {
    data: attributesData,
    refetch,
    isPending,
    isFetching,
} = useMetaClasses({
    target: 'User',
})
const route = useRoute()
const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
// Only the user statuses are needed here (the endpoint filters by type and returns every row of it).
const { data: statuses } = useEnumerates({ type: 'user_status' })
const { locale } = useAppRuntime()
const isVi = computed(() => locale.value === 'vi')
const form = inject('form')
const userSubmit = inject('userSubmit')
const btnState = inject('btnState')
const resetUserForm = inject('resetUserForm')
const initFormMeta = inject('initFormMeta')
const getUserToEdit = async () => {
    const data = await getUser(route.params.uuid, true)

    if (data.data) {
        resetUserForm()
        const newForm = objectAssignOnlyByKeyHasValue(form.value, data.data)
        newForm.avatar = data.data.avatar ? [data.data.avatar] : []
        if (newForm.meta) {
            Object.keys(newForm.meta).forEach((key) => {
                const meta = newForm.meta[key]
                if (meta && meta.id && meta.type === 'date') {
                    newForm.meta[key].value = meta.value ? dayjs(meta.value) : null
                }
            })
        }
        form.value = newForm
    }
}

onMounted(() => {
    if (!attributesData.value || attributesData.value.length === 0) {
        refetch()
    }
    initFormMeta()
    getUserToEdit()
})

watch([() => isPending.value, () => isFetching.value], ([isLoading, isFetching]) => {
    if (!isLoading && !isFetching) {
        nextTick(() => {
            //  Get value of attributesData and process to init form meta
            initFormMeta(attributesData.value)
        })
    }
})
</script>
