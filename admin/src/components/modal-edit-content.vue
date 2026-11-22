<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-button class="plain" size="small" type="ghost" @click="editContent">
        <span class="text-xs"><i-icon class="size-4" icon="edit" /></span>
        <span>{{ $t('nav.b.edit') }}</span>
    </a-button>

    <a-modal
        v-model:open="visible"
        :maskClosable="false"
        class="modal-fullscreen"
        wrap-class-name="is-fullscreen"
        @close="visible = false"
    >
        <template #title>
            <div class="flex items-start">
                <img v-if="form.language" :src="form.language.flag_url" alt="" class="mr-2 w-6" />
                <span>{{ $t('nav.b.update') }} {{ $t('validation.attributes.content') }}</span>
                <span class="pl-2">{{ form.name }}</span>
            </div>
        </template>

        <a-spin :spinning="isLoading">
            <a-form
                id="content-edit-form"
                :model="form"
                layout="vertical"
                @finish="submitForm"
                @finish-failed="finishFailed"
            >
                <div class="grid grid-cols-1 md:grid-cols-2 md:gap-x-4">
                    <div>
                        <a-form-item
                            :label="$t('validation.attributes.title')"
                            :rules="[
                                {
                                    required: true,
                                    trigger: 'blur',
                                    message: $t('validation.required', {
                                        a: $t('validation.attributes.title'),
                                    }),
                                },
                            ]"
                            name="name"
                        >
                            <a-input
                                v-model:value="form.name"
                                :maxlength="255"
                                :placeholder="$t('validation.attributes.title')"
                                show-count
                            />
                        </a-form-item>

                        <a-form-item
                            :label="$t('validation.attributes.video_url')"
                            :rules="[
                                {
                                    type: 'url',
                                    message: $t('validation.url', {
                                        a: $t('validation.attributes.video_url'),
                                    }),
                                },
                            ]"
                            name="video_url"
                        >
                            <a-input
                                v-model:value="form.video_url"
                                :maxlength="500"
                                :placeholder="$t('validation.attributes.video_url')"
                                show-count
                                type="url"
                            >
                                <template #suffix>
                                    <a-tooltip :title="$t('text.video_url')">
                                        <span class="relative">
                                            <i-icon class="size-4" icon="help-circle-outline" />
                                        </span>
                                    </a-tooltip>
                                </template>
                            </a-input>
                        </a-form-item>

                        <a-form-item :label="$t('validation.attributes.description')" name="description">
                            <a-textarea
                                v-model:value="form.description"
                                :maxlength="500"
                                :placeholder="$t('validation.attributes.description')"
                                :rows="5"
                                show-count
                            />
                        </a-form-item>
                    </div>

                    <div>
                        <media-selection
                            v-model="form.audios"
                            :limit="1"
                            folder="Content"
                            type="audio"
                        />

                        <a-form-item :label="$t('validation.attributes.status')" class="mt-3!" name="status">
                            <a-select
                                v-model:value="form.status"
                                :options="
                                    (statuses || [])
                                        .filter((e) => e.type === 'content_status')
                                        .map((s) => ({
                                            value: s.enum_key,
                                            label: `${s.name} (${s.enum_key})`,
                                        }))
                                "
                                :placeholder="$t('text.select')"
                                class="w-full"
                            >
                            </a-select>
                        </a-form-item>

                        <div v-if="rule('content.confirm')" class="is_confirmed">
                            <a-form-item
                                v-if="rule('content.confirm')"
                                :label="
                                    $t('validation.attributes.is_confirmed', {
                                        a: $t('nav.a.contents'),
                                    })
                                "
                                class="mt-3!"
                                name="is_confirmed"
                            >
                                <a-switch
                                    v-model:checked="form.is_confirmed"
                                    :checked-children="$t('text.confirm.yes')"
                                    :placeholder="$t('validation.attributes.latitude')"
                                    :un-checked-children="$t('text.confirm.no')"
                                />
                            </a-form-item>
                        </div>
                    </div>
                </div>

                <div class="pt-4">
                    <i-contents
                        v-model="form.content"
                        :plugins="['text', 'image', 'video']"
                        :target-id="form.id"
                        category="content"
                        no-label
                        target="Content"
                    ></i-contents>
                </div>
            </a-form>
        </a-spin>

        <template #footer>
            <div class="flex flex-nowrap items-center justify-end gap-2">
                <a-button :icon="h(StopOutlined)" @click="visible = false">
                    {{ $t('nav.b.cancel') }}
                </a-button>

                <a-button
                    :icon="h(SaveOutlined)"
                    :loading="btnState || isLoading"
                    :disabled="btnState || isLoading"
                    danger
                    form="content-edit-form"
                    html-type="submit"
                    type="primary"
                >
                    {{ $t('nav.b.save') }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { defineComponent, h, onMounted, ref } from 'vue'
import { isEmpty } from 'lodash-es'
import { getContent, storeContent, updateContent } from '@/libs'
import { finishFailed, iAlert, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { useEnumerates } from '@/hooks'
import { useI18n } from 'vue-i18n'
import { SaveOutlined, StopOutlined } from '@ant-design/icons-vue'

const { t } = useI18n()
const props = defineProps({
    uuid: { type: String, default: null },
})
const emit = defineEmits(['finish', 'update:visible'])
defineComponent({
    name: 'ModalEditContent',
})

//  Always get Enumerate status
const { data: statuses } = useEnumerates()
const visible = ref(false)
const isLoading = ref(false)
const formDefault = () => {
    return {
        id: 0,
        uuid: null,
        name: null,
        target_id: 0,
        target_type: null,
        language_id: 1,
        description: null,
        content: [{ type: 'text', value: null }],
        custom: {
            name: 'pending',
            description: 'pending',
            content: 'pending',
        },
        status: 'status_draft',
    }
}
const form = ref({})
const editContent = async () => {
    if (isEmpty(props.uuid)) return

    visible.value = true
    isLoading.value = true
    getContent(props.uuid, true)
        .then((res) => {
            if (isEmpty(res)) return

            form.value = objectAssignOnlyByKeyHasValue(formDefault(), res)
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            isLoading.value = false
        })
}
//  Submit button loading state
const btnState = ref(false)
//  Submit form
const submitForm = (data) => {
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    if (btnState.value) {
        return
    }
    btnState.value = true
    const called = value.id > 0 ? updateContent(value.uuid, value) : storeContent(value)
    called
        .then((res) => {
            if (res?.status === 'success') {
                emit('finish')
            }
            if (res?.message) {
                iAlert(res)
            }
        })
        .catch((err) => {
            iAlert({ ...err, title: t('mgs.title.error') }, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

onMounted(() => {
    form.value = formDefault()
})
</script>
