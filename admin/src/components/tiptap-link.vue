<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-popover
        v-model:open="popoverState"
        placement="bottom"
        :title="t('text.editor.link.set_link')"
        trigger="click"
    >
        <template #content>
            <a-form :model="innerValue" class="max-w-sm" layout="horizontal" @finish="submitForm">
                <a-form-item
                    :label="t('text.menu-url')"
                    :rules="[
                        {
                            required: true,
                            message: t('validation.required', { a: t('text.menu-url') }),
                            trigger: 'blur',
                        },
                    ]"
                    class="mb-2!"
                    name="href"
                >
                    <a-input
                        v-model:value="innerValue.href"
                        :placeholder="t('text.menu-url')"
                        type="text"
                    />
                </a-form-item>
                <a-form-item class="mb-2!" name="target">
                    <a-radio-group v-model:value="innerValue.target">
                        <a-radio value="_blank">{{ t('enum.click_blank') }}</a-radio>
                        <a-radio value="_self">{{ t('enum.click_self') }}</a-radio>
                    </a-radio-group>
                </a-form-item>
                <a-form-item class="mb-2!" :label="t('text.editor.link.rel_label')" name="rel">
                    <a-input
                        v-model:value="innerValue.rel"
                        :placeholder="t('text.editor.link.rel_placeholder')"
                        type="text"
                    />
                </a-form-item>
                <a-form-item class="mb-0!">
                    <div class="flex items-center">
                        <div class="flex-1">
                            <a-button type="default" @click="() => clearLink()">
                                {{ t('nav.b.delete') }}
                            </a-button>
                        </div>
                        <a-button
                            :disabled="linkSubmitting"
                            :loading="linkSubmitting"
                            html-type="submit"
                            type="primary"
                        >
                            {{ t('nav.b.save') }}
                        </a-button>
                    </div>
                </a-form-item>
            </a-form>
        </template>

        <button
            :class="
                classNames(
                    isActive('link') ? 'bg-cyan-600 text-white' : '',
                    'hover:bg-gray-200 hover:text-gray-900 text-gray-600 text-center text-base rounded-sm',
                    'w-6 h-6 inline-flex items-center justify-center'
                )
            "
            @click="onOpen"
        >
            <i-icon icon="link" class="size-4" />
        </button>
    </a-popover>
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'
import { classNames } from '@/mixins'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'TipTapLink',
})

const { t } = useI18n()

const { modelValue, editor, toolbar, isActive } = defineProps({
    modelValue: {
        type: Object,
        default: () => ({
            isIncludeHeader: false,
        }),
    },
    editor: {
        type: Object,
    },
    toolbar: {
        type: Object,
    },
    isActive: {
        type: Function,
    },
})

const emit = defineEmits(['update:modelValue'])

const defaultValue = {
    href: '',
    target: '_blank',
    className: '',
    id: '',
    rel: 'noopener noreferrer nofollow',
    title: '',
}

const innerValue = ref(defaultValue)
const linkSubmitting = ref(false)

//  Popover state
const popoverState = ref(false)
//  Open table
const onOpen = () => {
    popoverState.value = true
}

const clearLink = () => {
    popoverState.value = false

    editor.chain().focus().unsetLink().run()
}

const submitForm = () => {
    if (linkSubmitting.value) {
        return
    }

    linkSubmitting.value = true
    popoverState.value = false

    editor.chain().focus().setLink(innerValue.value).run()
    linkSubmitting.value = false
    //  Add title into this link
    // editor.getAttributes('link').title = innerValue.value.title
}

// const getCurrentSelectedString = () => {
//     if (editor.state.selection) {
//         const { from, to } = editor.state.selection
//         const stringSelected = editor.getHTML()
//         //  Remove tag
//         const newString = stringSelected.replace(/<[^>]*>/g, '')
//
//         //  Remove a
//         return newString.toString().substring(from - 1, to)
//     }
//
//     return ''
// }

watch(
    () => editor.getAttributes('link'),
    (value) => {
        innerValue.value = { ...defaultValue, ...value }
    }
)

// onMounted(() => {
//     innerValue.value = { ...innerValue.value, title: getCurrentSelectedString() }
// })
</script>
