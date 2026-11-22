<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <transition name="accordion">
        <a-form v-show="open" :model="form" layout="vertical" @finish="submitForm">
            <div class="p-4 bg-white/80 mt-4 shadow-sm border rounded-sm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <a-form-item
                        name="name"
                        class="max-w-xs"
                        :label="`${$t('validation.attributes.name')} ${$t('text.widget.inputs')}`"
                        :rules="[
                            {
                                required: true,
                                trigger: 'blur',
                                message: $t('validation.required', {
                                    a: `${$t('validation.attributes.name')} ${$t('text.widget.inputs')}`,
                                }),
                            },
                        ]"
                    >
                        <a-input
                            v-model:value="form.name"
                            :placeholder="`${$t('validation.attributes.name')} ${$t('text.widget.inputs')}`"
                            disabled
                        />
                    </a-form-item>
                    <a-form-item
                        name="type"
                        class="max-w-xs"
                        :label="`${$t('validation.attributes.name')} ${$t('text.widget.inputs')}`"
                        :rules="[
                            {
                                required: true,
                                trigger: 'change',
                                message: $t('validation.required', {
                                    a: `${$t('validation.attributes.name')} ${$t('text.widget.inputs')}`,
                                }),
                            },
                        ]"
                    >
                        <a-select
                            v-model:value="form.type"
                            :placeholder="$t('text.select')"
                            :options="inputTypeOptions"
                        />
                    </a-form-item>
                    <div v-if="['select', 'checkbox', 'radio'].includes(form.type)">
                        <div class="flex flex-col w-full">
                            <div class="flex">
                                <div class="flex-1">{{ $t('text.options') }}</div>
                                <a-button class="btn-succes" size="small" @click="addOption">
                                    {{ $t('nav.b.add') }} {{ $t('text.option') }}
                                </a-button>
                            </div>
                            <div
                                v-for="(e, ind) in form.options"
                                :key="ind"
                                class="flex items-center gap-1 pt-2 options-list"
                            >
                                <a-form-item no-style :name="['options', ind, 'value']">
                                    <a-input
                                        v-model:value="e.value"
                                        :placeholder="$t('validation.attributes.value')"
                                        size="small"
                                        required
                                        :addon-before="$t('validation.attributes.value')"
                                    />
                                </a-form-item>
                                <a-form-item no-style :name="['options', ind, 'label']">
                                    <a-input
                                        v-model:value="e.label"
                                        :placeholder="$t('text.e.label')"
                                        class="m-0"
                                        size="small"
                                        :addon-before="$t('text.label')"
                                    />
                                </a-form-item>
                                <a-button
                                    :disabled="form.options.length < 3"
                                    danger
                                    size="small"
                                    type="primary"
                                    @click="removeOption(ind)"
                                >
                                    <i-icon icon="delete" class="size-4" />
                                </a-button>
                            </div>
                            <p
                                class="text-xs text-gray-500 break-normal mt-1"
                                v-html="$t('text.e.options')"
                            />
                        </div>
                    </div>

                    <a-form-item
                        v-if="form.type === 'image'"
                        :name="['options', 0, 'is_background']"
                        class="max-w-xs"
                        :label="$t('text.widget.input_option.is_background')"
                    >
                        <a-switch
                            v-model:checked="form.options[0].is_background"
                            :checked-children="$t('text.turn-on')"
                            :un-checked-children="$t('text.turn-off')"
                        />
                    </a-form-item>
                    <a-form-item
                        name="default_value"
                        class="max-w-xs"
                        :label="`${$t('validation.attributes.default_value')} ${$t('text.widget.inputs')}`"
                    >
                        <a-input
                            v-model:value="form.default_value"
                            :placeholder="`${$t('validation.attributes.default_value')} ${$t('text.widget.inputs')}`"
                        />
                    </a-form-item>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4">
                    <a-button @click="closeForm" :icon="h(CloseCircleOutlined)">
                        {{ $t('nav.b.cancel') }}
                    </a-button>
                    <a-button
                        danger
                        type="primary"
                        class="btn-danger plain"
                        :icon="h(SaveOutlined)"
                        @click="submitForm"
                    >
                        {{ $t('nav.b.save') }}
                    </a-button>
                </div>
            </div>
        </a-form>
    </transition>
</template>

<script setup>
import { computed, defineComponent, ref, watch, h } from 'vue'
import { CloseCircleOutlined, SaveOutlined } from '@ant-design/icons-vue'
import { widgetInputDefault } from '@/hooks'
import { iAlert } from '@/mixins'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineComponent({
    name: 'WidgetInputForm',
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

const form = ref(widgetInputDefault())
const inputTypeOptions = computed(() => [
    { value: 'text', label: t('enum.text') },
    { value: 'textarea', label: t('enum.textarea') },
    { value: 'checkbox', label: t('enum.checkbox') },
    { value: 'radio', label: t('enum.radio') },
    { value: 'select', label: t('enum.select') },
    { value: 'image', label: t('enum.image') },
    { value: 'query', label: t('enum.search') },
    { value: 'list', label: t('text.widget.input_type.list') },
])

const closeForm = () => {
    // Reset the local form state.
    form.value = {}
    // Close the drawer/form container.
    emits('update:open', false)
}

const submitForm = (value = {}) => {
    const newForm = { ...form.value, ...value }
    // Validate choice-type inputs before emitting the change.
    if (['select', 'checkbox', 'radio'].includes(newForm.type)) {
        // All option values must be present.
        if (newForm.options.some((e) => !e.value)) {
            iAlert(t('validation.required', { a: t('validation.attributes.value') }), true)
            return
        }

        if (newForm.options.length < 2) {
            iAlert(t('text.widget.inputs') + ' ' + t('text.e.options'), true)
            return
        }
    }

    emits('change', newForm)
}
/** Add a new option row. */
const addOption = () => {
    form.value.options.push({
        label: null,
        value: null,
    })
}
/** Remove an option row. */
const removeOption = (index) => {
    form.value.options.splice(index, 1)
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

watch(
    () => form.value.type,
    (val) => {
        if (['select', 'checkbox', 'radio'].includes(val) && !form.value.options.length) {
            form.value.options.push({
                label: null,
                value: null,
            })
        } else if (val === 'image' && !form.value.options.length) {
            form.value.options.push({
                is_background: false,
            })
        }
    }
)
</script>
<style>
.accordion-enter-active,
.accordion-leave-active {
    transition: max-height 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
    overflow: hidden;
}

.accordion-enter-from,
.accordion-leave-to {
    opacity: 0;
    max-height: 0;
}

.accordion-enter-to,
.accordion-leave-from {
    opacity: 1;
    max-height: 500px;
}
</style>
