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
                text: $t('nav.a.email-send'),
                title: $t('nav.a.email-send'),
                icon: 'email-edit-outline',
            },
        ]"
    />

    <div class="i-box">
        <a-form
            ref="formRef"
            class="bg-white p-4"
            :label-col="{ span: 4 }"
            :model="form"
            :rules="formRule"
            @finish="submitForm"
        >
            <div
                class="border border-dashed border-gray-300 bg-gray-50/80 rounded-md p-4 pb-0 mb-4 relative"
            >
                <label class="absolute -top-3 bg-linear-to-t from-gray-50/80 to-white px-2">
                    {{ $t('text.email_receive') }}
                </label>

                <select-input-contact
                    v-model="form.email"
                    :disabled="!!form.to_email"
                    :label="$t('text.select-from-list')"
                    name="email"
                    @contact-selected="setRecipientContact"
                />

                <div v-if="!form.email" data-test="manual-recipient-fields">
                    <a-divider class="mb-2! mt-0! text-sm!" orientation="left">
                        {{ $t('text.or-input-email') }}
                    </a-divider>

                    <a-form-item :label="$t('validation.attributes.to_email')" name="to_email">
                        <a-input
                            v-model:value="form.to_email"
                            :placeholder="$t('validation.attributes.to_email')"
                            type="email"
                        />
                    </a-form-item>

                    <a-form-item :label="$t('validation.attributes.to_name')" name="to_name">
                        <a-input
                            v-model:value="form.to_name"
                            :placeholder="$t('validation.attributes.to_name')"
                        />
                    </a-form-item>
                </div>
            </div>

            <a-form-item :label="$t('validation.attributes.subject')" name="subject">
                <a-input
                    v-model:value="form.subject"
                    :placeholder="$t('validation.attributes.subject')"
                />
            </a-form-item>

            <select-input-contact v-model="form.cc" multiple label="Cc" name="cc" />
            <select-input-contact v-model="form.bcc" multiple label="Bcc" name="bcc" />

            <a-form-item :label="$t('text.email-content')" name="content">
                <div class="block w-full relative bg-white">
                    <tip-tap-editor v-model="form.content" />
                </div>
            </a-form-item>

            <a-form-item>
                <div class="space-x-2 flex items-center">
                    <a-button @click="goBack">{{ $t('nav.b.cancel') }}</a-button>
                    <a-button
                        class="btn-danger"
                        html-type="submit"
                        :loading="btnState"
                        :disabled="btnState"
                    >
                        <span><i-icon icon="send-variant" /></span>
                        <span>{{ $t('nav.b.send') }}</span>
                    </a-button>
                </div>
            </a-form-item>
        </a-form>
    </div>
</template>

<script>
import { defineComponent, nextTick, ref, inject, toRaw } from 'vue'
import { cloneDeep } from 'lodash-es'
import { iAlert, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { storeEmail } from '@/libs'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import TipTapEditor from '@/components/tiptap-editor.vue'

export default defineComponent({
    name: 'ComposeMail',
    methods: { rule },
    components: { TipTapEditor },
    setup() {
        const { t } = useI18n()
        const router = useRouter()
        const getCounter = inject('getCounter')
        const btnState = ref(false)

        /** Form email */
        const formDefault = () => {
            return {
                subject: null,
                name: null,
                email: null,
                from_name: null,
                from_email: null,
                to_name: null,
                to_email: null,
                reply_to: null,
                cc: [],
                bcc: [],
                content: null,
                contact_id: null,
                user_id: 0,
                reply_id: 0,
                files: [],
                type: 'sent',
            }
        }
        const form = ref(formDefault())
        const formRef = ref(null)
        const formRule = {
            subject: [
                {
                    required: true,
                    trigger: 'blur',
                    message: t('validation.required', { a: t('validation.attributes.subject') }),
                },
            ],
            content: [
                {
                    required: true,
                    trigger: 'blur',
                    message: t('validation.required', { a: t('text.email-content') }),
                },
            ],
        }
        const resetForm = () => {
            form.value = formDefault()

            nextTick(() => {
                formRef.value?.resetFields()
                formRef.value?.clearValidate()
            })
        }
        const setRecipientContact = (contact) => {
            form.value.contact_id = contact?.id ?? null
            form.value.name = contact?.name || contact?.email || null
        }
        const submitForm = (data = {}) => {
            if (btnState.value) {
                return
            }
            btnState.value = true
            const submitData = Object.fromEntries(
                Object.entries(data).filter(([, fieldValue]) => fieldValue !== null && fieldValue !== undefined)
            )
            const value = objectAssignOnlyByKeyHasValue(
                cloneDeep(toRaw(form.value)),
                submitData
            )
            value.email = value.email || value.to_email
            value.name = value.name || value.to_name
            delete value.to_email
            delete value.to_name

            storeEmail(value)
                .then((res) => {
                    if (res.status === 'success') {
                        resetForm()
                        getCounter()
                    }
                    if (res.message) {
                        iAlert(res)
                    }
                })
                .catch((err) => {
                    iAlert(err, true)
                })
                .finally(() => {
                    btnState.value = false
                })
        }

        const goBack = () => {
            router.go(-1)
        }

        return {
            form,
            formRef,
            formRule,
            btnState,
            setRecipientContact,
            resetForm,
            submitForm,
            goBack,
        }
    },
})
</script>
