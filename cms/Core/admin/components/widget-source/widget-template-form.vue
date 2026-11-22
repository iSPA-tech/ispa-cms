<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-modal
        :open="open"
        :title="$t('text.widget.template_form')"
        @cancel="closeForm"
        class="top-8 lg:top-12 w-full lg:w-[80%]"
    >
        <a-form id="widget-template-form" :model="form" layout="vertical" @finish="submitForm">
            <div class="p-4 bg-white/80 mt-4 shadow-sm border rounded-sm">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="">
                        <a-form-item
                            name="name"
                            class="max-w-xs"
                            :label="$t('text.widget_library.name_original')"
                            :rules="[
                                {
                                    required: true,
                                    trigger: 'blur',
                                    message: $t('validation.required', {
                                        a: $t('text.widget_library.name_original'),
                                    }),
                                },
                            ]"
                        >
                            <a-input
                                v-model:value="form.name"
                                :placeholder="$t('text.widget_library.name_original')"
                            >
                                <template #suffix></template>
                            </a-input>
                        </a-form-item>
                        <widget-name-translations v-model="form.name_translations" />
                        <p class="mb-4 mt-0 break-words text-xs text-slate-600">
                            {{ $t('text.widget_library.names_hint') }}
                        </p>

                        <select-input-project
                            v-model="form.project_name"
                            :label="$t('text.template_id')"
                            name="project_name"
                        />
                    </div>
                    <div class="">
                        <p>{{ $t('text.widget.available_input') }}</p>
                        <ul class="italic text-xs text-gray-600">
                            <li>
                                {query:model.name variable="$item" limit="0" template=`html Laravel
                                blade code &#123;&#123; $item->name &#125;&#125;`}
                            </li>
                            <li>{image name="input_name" source="" class="" alt=""}</li>
                            <li>{upload name="uploaded_media"}</li>
                            <li>{iSeo:key}</li>
                            <li>{any_name}</li>
                        </ul>
                    </div>
                </div>
                <div class="flex items-stretch gap-4 md:flex-row flex-col">
                    <div class="flex-1">
                        <a-form-item
                            name="content"
                            :label="`${$t('validation.attributes.content')} ${$t('validation.attributes.widget_template')}`"
                            class="mb-0"
                            :rules="[
                                {
                                    required: true,
                                    trigger: 'blur',
                                    message: $t('validation.required', {
                                        a: `${$t('validation.attributes.content')} ${$t('validation.attributes.widget_template')}`,
                                    }),
                                },
                            ]"
                        >
                            <a-textarea
                                v-model:value="form.content"
                                :placeholder="`${$t('validation.attributes.content')} ${$t('validation.attributes.widget_template')}`"
                                :rows="10"
                                class="h-full"
                            />
                        </a-form-item>
                        <p
                            class="text-xs italic text-gray-600"
                            v-html="$t('text.widget.template-info')"
                        />
                    </div>
                    <div class="flex-1">
                        <label for="">{{
                            $t('validation.attributes.widget_template_preview')
                        }}</label>
                        <SafeHtml
                            class="mt-2 p-4 rounded-sm bg-gray-50 border shadow-sm overflow-auto"
                            :html="form.content"
                        />
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4">
                    <a-form-item
                        name="css"
                        :label="`${$t('text.cssStyle')} ${$t('validation.attributes.widget_template')}`"
                        class="mb-0"
                    >
                        <a-textarea
                            v-model:value="form.css"
                            :placeholder="`${$t('text.cssStyle')} ${$t('validation.attributes.widget_template')}`"
                            :rows="5"
                        />
                    </a-form-item>
                    <a-form-item name="query" :label="$t('text.widget.query')" class="mb-0">
                    </a-form-item>
                </div>
            </div>
        </a-form>
        <template #footer>
            <div class="flex flex-nowrap items-center justify-end space-x-3 pt-4">
                <a-button @click="closeForm" :icon="h(CloseCircleOutlined)">
                    {{ $t('nav.b.cancel') }}
                </a-button>
                <a-button
                    form="widget-template-form"
                    danger
                    type="primary"
                    class="btn-danger plain"
                    html-type="submit"
                    :icon="h(SaveOutlined)"
                >
                    {{ $t('nav.b.save') }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { defineComponent, ref, watch, h } from 'vue'
import { CloseCircleOutlined, SaveOutlined } from '@ant-design/icons-vue'
import { widgetTemplateDefault } from '@/hooks'
import SafeHtml from '@/components/common/SafeHtml.vue'
import WidgetNameTranslations from './widget-name-translations.vue'

defineComponent({
    name: 'WidgetTemplateForm',
})

const props = defineProps({
    modelValue: {
        type: [Object],
        default: () => {},
    },
    open: {
        type: Boolean,
        default: false,
    },
})

const emits = defineEmits(['update:modelValue', 'update:open', 'change'])

const form = ref(widgetTemplateDefault())

const closeForm = () => {
    //  Reset form value
    form.value = widgetTemplateDefault()
    //  Close form
    emits('update:open', false)
}

const submitForm = (value = {}) => {
    emits('change', { ...form.value, ...value })
}

watch(
    () => props.modelValue,
    (val) => {
        form.value = {
            ...form.value,
            ...val,
        }
    }
)
</script>
