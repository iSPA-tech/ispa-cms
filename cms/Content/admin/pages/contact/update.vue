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
                text: $t('nav.b.create'),
                title: $t('nav.b.create'),
            },
        ]"
    >
        <i-button variant="default" @click="goBack">
            <template #icon>
                <i-icon class="size-3.5" icon="arrow-left" />
            </template>
            <span>{{ $t('nav.b.back') }}</span>
        </i-button>
        <i-button
            :disabled="btnState"
            :loading="btnState"
            form="contact-form"
            native-type="submit"
            variant="primary"
        >
            <template #icon>
                <i-icon class="size-3.5" icon="content-save" />
            </template>
            <span>{{ $t('nav.b.create') }}</span>
        </i-button>
    </breadcrumbs>

    <i-box v-if="rule('contact.create')">
        <a-form
            id="contact-form"
            ref="formRef"
            :model="form"
            layout="vertical"
            @finish="submitForm"
        >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                <div class="space-y-0">
                    <a-form-item
                        :label="$t('validation.attributes.name')"
                        name="name"
                        :rules="[
                            {
                                required: true,
                                trigger: 'blur',
                                message: $t('validation.required', {
                                    a: $t('validation.attributes.name'),
                                }),
                            },
                        ]"
                    >
                        <a-input
                            v-model:value="form.name"
                            :maxlength="255"
                            :placeholder="$t('validation.attributes.name')"
                            show-count
                        />
                    </a-form-item>

                    <a-form-item
                        :label="$t('validation.attributes.email')"
                        name="email"
                        :rules="[
                            {
                                type: 'email',
                                trigger: 'blur',
                                message: $t('validation.email', {
                                    a: $t('validation.attributes.email'),
                                }),
                            },
                        ]"
                    >
                        <a-input
                            v-model:value="form.email"
                            :maxlength="255"
                            :placeholder="$t('validation.attributes.email')"
                            type="email"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.mobile')" name="mobile">
                        <a-input
                            v-model:value="form.mobile"
                            :maxlength="20"
                            :placeholder="$t('validation.attributes.mobile')"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.birthday')" name="birthday">
                        <a-date-picker
                            v-model:value="form.birthday"
                            class="w-full"
                            :placeholder="$t('validation.attributes.birthday')"
                            value-format="YYYY-MM-DD"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.gender')" name="gender">
                        <a-select
                            v-model:value="form.gender"
                            :placeholder="$t('validation.attributes.gender')"
                            allow-clear
                        >
                            <a-select-option :value="0">{{
                                $t('text.gender.unknown')
                            }}</a-select-option>
                            <a-select-option :value="1">{{
                                $t('text.gender.male')
                            }}</a-select-option>
                            <a-select-option :value="2">{{
                                $t('text.gender.female')
                            }}</a-select-option>
                        </a-select>
                    </a-form-item>
                </div>

                <div class="space-y-0">
                    <a-form-item
                        :label="$t('validation.attributes.organization')"
                        name="organization"
                    >
                        <a-input
                            v-model:value="form.organization"
                            :maxlength="255"
                            :placeholder="$t('validation.attributes.organization')"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.address')" name="address">
                        <a-textarea
                            v-model:value="form.address"
                            :rows="2"
                            :maxlength="500"
                            :placeholder="$t('validation.attributes.address')"
                            show-count
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.url')" name="url">
                        <a-input
                            v-model:value="form.url"
                            :maxlength="255"
                            :placeholder="$t('validation.attributes.url')"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.source')" name="source">
                        <a-input
                            v-model:value="form.source"
                            :maxlength="100"
                            :placeholder="$t('validation.attributes.source')"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.type')" name="type">
                        <a-input
                            v-model:value="form.type"
                            :maxlength="100"
                            :placeholder="$t('validation.attributes.type')"
                        />
                    </a-form-item>

                    <a-form-item name="is_subscribe">
                        <a-checkbox v-model:checked="form.is_subscribe">
                            {{ $t('text.is_subscribe') }}
                        </a-checkbox>
                    </a-form-item>
                </div>
            </div>
        </a-form>
    </i-box>
    <no-permission-error v-else />
</template>

<script setup>
import { defineComponent, ref } from 'vue'
import { useRouter } from 'vue-router'
import { iAlert, rule } from '@/mixins'
import { storeContact } from '@/libs'

defineComponent({
    name: 'ContactCreate',
})

const router = useRouter()
const btnState = ref(false)
const formRef = ref(null)

const formDefault = () => ({
    name: '',
    email: '',
    mobile: '',
    address: '',
    organization: '',
    url: '',
    source: '',
    type: '',
    birthday: null,
    gender: 0,
    is_subscribe: false,
})

const form = ref(formDefault())

const submitForm = async () => {
    if (btnState.value) {
        return
    }
    btnState.value = true
    try {
        const res = await storeContact(form.value)
        if (res.data.message) {
            iAlert(res.data)
        }
        if (res.data.status === 'success') {
            router.push({ name: 'contact-manage' })
        }
    } catch (err) {
        iAlert(err, true)
    } finally {
        btnState.value = false
    }
}

const goBack = () => {
    router.go(-1)
}
</script>
