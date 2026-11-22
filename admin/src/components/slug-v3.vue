<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item
        :rules="[
            {
                required: true,
                trigger: 'blur',
                message: $t('text.slug.name_required'),
            },
        ]"
        class="more_tools"
        :name="name"
    >
        <template #label>
            <div class="flex items-center flex-1">
                <span class="flex-1 whitespace-nowrap">
                    {{ titleText }}
                </span>

                <p
                    class="text-xs text-right text-cyan-500 line-clamp-1 inline-flex items-center space-x-1 pl-8"
                >
                    <span v-if="!editState">{{ innerForm.slugs.slug || '-' }}</span>
                    <a-tooltip :title="$t('text.slug.edit_tooltip')">
                        <span
                            @click="toggleSlugEdit"
                            role="button"
                            class="flex rounded-sm bg-white hover:bg-blk-100 transition-all size-6 p-1"
                        >
                            <i-icon
                                icon="pencil-outline"
                                class="size-4 text-slate-600 hover:text-slate-800"
                            />
                        </span>
                    </a-tooltip>
                </p>
            </div>
        </template>

        <a-input
            v-model:value="innerForm[name]"
            :maxlength="limit > 0 ? limit : undefined"
            :placeholder="titleText"
            :show-count="limit > 0"
            @change="checkNameSlugDebounced"
        />
    </a-form-item>

    <a-form-item
        v-if="rule('slug.viewAny') && innerForm.slugs && editState"
        :label="$t('validation.attributes.slug')"
        :name="['slugs', 'slug']"
        class="slug-editable"
    >
        <a-input
            v-model:value="innerForm.slugs.slug"
            :disabled="editable || slugEditLocked"
            :loading="loadingState"
            :placeholder="$t('validation.attributes.slug')"
            @change="checkSlugDebounced"
        />
    </a-form-item>
</template>

<script setup>
import { computed, defineComponent, ref, watch } from 'vue'
import ajax from '@/ajax'
import { iAlert, rule } from '@/mixins'
import { isEmpty, debounce } from 'lodash-es'
import { Modal } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'

const { t } = useI18n()

const props = defineProps({
    module: { type: String, default: null },
    label: { type: String, default: null },
    editable: { type: Boolean, default: false },
    modelValue: {
        type: Object,
        default: () => {
            return {
                name: null,
                slugs: {
                    slug: null,
                    id: null,
                    target_id: null,
                },
            }
        },
    },
    open: { type: Boolean, default: false },
    limit: {
        type: Number,
        default: 0,
        validator(value) {
            return value >= 0
        },
    },
    name: { type: String, default: 'name' },
})
const emit = defineEmits(['update:modelValue'])
defineComponent({
    name: 'SlugV3',
})

/**
 * Title field caption and placeholder: one parameterised phrase when a label is given.
 */
const titleText = computed(() =>
    props.label ? t('text.slug.title_with_label', { label: props.label }) : t('validation.attributes.title'),
)

const innerForm = ref(props.modelValue)
const editState = ref(props.open, false)
const loadingState = ref(false)
const slugEditConfirmed = ref(false)

const publishedStatuses = ['status_published', 'content_published', 'published']

const isPublishedRecord = (value = {}) => {
    const hasPersistedTarget = Boolean(value.id || value.uuid || value.slugs?.target_id)

    if (!hasPersistedTarget) {
        return false
    }

    if (value.published_at) {
        const publishedAt = dayjs(value.published_at)

        if (publishedAt.isValid()) {
            return !publishedAt.isAfter(dayjs())
        }
    }

    if (Object.prototype.hasOwnProperty.call(value, 'isPublished')) {
        return value.isPublished === true
    }

    return publishedStatuses.includes(value.status)
}

const publishedRecord = computed(() => isPublishedRecord(innerForm.value))
const slugEditLocked = computed(() => publishedRecord.value && !slugEditConfirmed.value)

//  Update parent model value
const updateModelValue = () => {
    emit('update:modelValue', innerForm.value)
}

const toggleSlugEdit = () => {
    if (!slugEditLocked.value) {
        editState.value = !editState.value
        return
    }

    Modal.confirm({
        title: t('mgs.confirm.slug_edit_title'),
        content: t('mgs.confirm.slug_edit_content'),
        okText: t('nav.b.confirm'),
        cancelText: t('nav.b.cancel'),
        onOk() {
            slugEditConfirmed.value = true
            editState.value = true
        },
    })
}

/** Check slug available */
const checkSlug = (e) => {
    if (slugEditLocked.value) {
        return
    }

    const value = e.target.value
    if (!isEmpty(value)) {
        //  Block edit if it's category and locked
        if (props.module === 'category' && !rule('category.locked') && props.editable) {
            return
        }

        loadingState.value = true
        ajax.post(`/slug/check-slug`, {
            slug: value,
            table: props.module,
            id: innerForm.value.slugs.id || 0,
            target_id: innerForm.value.slugs.target_id || innerForm.value.id || 0,
            language_id: innerForm.value.language_id || 0,
        })
            .then((res) => {
                if (res.data.status === 'success') {
                    innerForm.value.slugs.slug = res.data.data
                }
                if (res.data.message) {
                    iAlert(res.data)
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
            .finally(() => {
                loadingState.value = false
            })
    } else {
        innerForm.value.slugs.slug = null
    }
}
//  Debounce search product
const checkSlugDebounced = ref(debounce(checkSlug, 500))
const checkNameSlugDebounced = (e) => {
    if (slugEditLocked.value) {
        return
    }

    checkSlugDebounced.value(e)
}

//  Watch any changed from innerForm, then update parent model value
watch(
    () => innerForm.value,
    () => {
        if (innerForm.value !== props.modelValue) {
            updateModelValue()
        }
    }
)

//  Watch any changed from parent model value, then set it into innerForm
watch(
    () => props.modelValue,
    (val) => {
        innerForm.value = val
    },
    { deep: true, immediate: true }
)

watch(
    () => [
        props.modelValue?.id,
        props.modelValue?.uuid,
        props.modelValue?.slugs?.target_id,
        props.modelValue?.isPublished,
        props.modelValue?.status,
        props.modelValue?.published_at,
    ],
    () => {
        slugEditConfirmed.value = false
        editState.value = Boolean(props.open) && !isPublishedRecord(props.modelValue)
    },
    { immediate: true },
)
</script>
