<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-modal
        v-model:open="state.open"
        :title="
            $t(state.isEdit ? 'text.workflow.form.update_title' : 'text.workflow.form.create_title')
        "
        :keyboard="false"
        :maskClosable="false"
        :confirm-loading="state.loading"
        destroy-on-close
        width="640px"
        @cancel="closeForm"
    >
        <a-form
            id="workflowForm"
            ref="formRef"
            :model="form"
            :rules="formRules"
            layout="vertical"
            @finish="submitForm"
            @finish-failed="finishFailed"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <a-form-item :label="$t('validation.attributes.name')" name="name">
                    <a-input v-model:value="form.name" :placeholder="$t('validation.attributes.name')" />
                </a-form-item>
                <a-form-item :label="$t('validation.attributes.slug')" name="slug">
                    <a-input
                        v-model:value="form.slug"
                        :placeholder="$t('validation.attributes.slug')"
                        @focus="slugTouched = true"
                    />
                </a-form-item>
            </div>

            <a-form-item :label="$t('validation.attributes.tags')">
                <a-select
                    v-model:value="form.tags"
                    mode="tags"
                    :options="tagOptions"
                    allow-clear
                    :placeholder="$t('text.workflow.form.tags_placeholder')"
                />
            </a-form-item>

            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                <a-form-item :label="$t('validation.attributes.description')" class="mb-0 md:mb-4">
                    <a-textarea
                        v-model:value="form.description"
                        :rows="3"
                        :placeholder="$t('text.workflow.form.description_placeholder')"
                    />
                </a-form-item>
                <a-form-item :label="$t('text.workflow.form.active')" class="mb-0 md:text-center">
                    <a-switch v-model:checked="form.is_active" />
                </a-form-item>
            </div>
            <a-collapse :bordered="false" class="mt-2 bg-transparent" data-testid="workflow-form-advanced">
                <a-collapse-panel key="advanced" :header="$t('text.workflow.form.advanced')" force-render>
                    <p class="mb-3 text-xs text-gray-500">{{ $t('text.workflow.form.advanced_hint') }}</p>
                    <div class="grid gap-4 md:grid-cols-2">
                        <a-form-item :label="$t('text.workflow.form.trigger_event')" name="trigger_event">
                            <a-auto-complete
                                v-model:value="form.trigger_event"
                                :options="triggerOptions"
                                :placeholder="'order.created'"
                                allow-clear
                            />
                        </a-form-item>
                        <a-form-item :label="$t('text.workflow.form.target_entity')" name="target_entity_type">
                            <a-auto-complete
                                v-model:value="form.target_entity_type"
                                :options="targetOptions"
                                :placeholder="'App\\\\Models\\\\Order'"
                                allow-clear
                            />
                        </a-form-item>
                    </div>
                </a-collapse-panel>
            </a-collapse>
        </a-form>

        <template #footer>
            <div class="flex flex-nowrap items-center justify-end gap-2">
                <a-button :icon="h(CloseCircleOutlined)" @click="closeForm">
                    {{ $t('nav.b.cancel') }}
                </a-button>
                <a-button
                    type="primary"
                    :icon="h(SaveOutlined)"
                    form="workflowForm"
                    html-type="submit"
                    :loading="state.loading"
                    :disabled="state.loading"
                >
                    {{ state.isEdit ? $t('nav.b.save') : $t('nav.b.create') }}
                </a-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { CloseCircleOutlined, SaveOutlined } from '@ant-design/icons-vue'
import { computed, h, nextTick, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { finishFailed, iAlert } from '@/mixins'
import { storeWorkflow, updateWorkflow } from '@/libs'

defineOptions({
    name: 'WorkflowFormModal',
})

const props = defineProps({
    tagOptions: {
        type: Array,
        default: () => [],
    },
    triggerOptions: {
        type: Array,
        default: () => [],
    },
    targetOptions: {
        type: Array,
        default: () => [],
    },
    activationState: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['saved'])

const { t } = useI18n()
const formRef = ref()
const slugTouched = ref(false)

const formDefault = () => ({
    id: 0,
    uuid: '',
    name: '',
    slug: '',
    trigger_event: '',
    target_entity_type: '',
    description: '',
    is_active: true,
    tags: [],
})

const form = reactive(formDefault())

const state = reactive({
    open: false,
    loading: false,
    isEdit: false,
})

const formRules = computed(() => ({
    name: [
        {
            required: true,
            message: t('validation.required', { a: t('validation.attributes.name') }),
            trigger: 'blur',
        },
    ],
    slug: [
        {
            required: true,
            message: t('validation.required', { a: t('validation.attributes.slug') }),
            trigger: 'blur',
        },
        {
            pattern: /^[a-z0-9-_.]+$/,
            message: t('text.workflow.form.slug_rule'),
            trigger: 'blur',
        },
    ],
}))

const resetForm = () => {
    Object.assign(form, formDefault())
    slugTouched.value = false
    state.loading = false
}

const closeForm = () => {
    state.open = false
}

watch(
    () => state.open,
    (open) => {
        if (!open) {
            nextTick(() => {
                formRef.value?.resetFields?.()
                resetForm()
            })
        }
    }
)

const generateSlug = (value = '') =>
    value
        .toString()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9\s-_.]/g, '')
        .trim()
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .toLowerCase()

watch(
    () => form.name,
    (val) => {
        if (!state.isEdit && !slugTouched.value) {
            form.slug = generateSlug(val || '')
        }
    }
)

const openCreate = () => {
    resetForm()
    state.isEdit = false
    state.open = true
    nextTick(() => formRef.value?.clearValidate?.())
}

const openEdit = (workflow) => {
    if (!workflow) return
    state.isEdit = true
    state.open = true
    Object.assign(form, {
        id: workflow.id,
        uuid: workflow.uuid || '',
        name: workflow.name,
        slug: workflow.slug,
        trigger_event: workflow.trigger_event,
        target_entity_type: workflow.target_entity_type,
        description: workflow.description,
        is_active: props.activationState?.[workflow.id] ?? workflow.is_active,
        tags: [...(workflow.tags || [])],
    })
    slugTouched.value = true
    nextTick(() => formRef.value?.clearValidate?.())
}

// A workflow that starts from a step of the "Start" group needs no event or record type; the API still wants both.
const DEFAULT_TRIGGER_EVENT = 'manual'
const DEFAULT_TARGET_ENTITY = 'none'

const submitForm = () => {
    if (state.loading) return
    formRef.value
        ?.validate()
        .then(async () => {
            state.loading = true
            const payload = {
                name: form.name,
                slug: form.slug,
                trigger_event: (form.trigger_event || '').trim() || DEFAULT_TRIGGER_EVENT,
                target_entity_type: (form.target_entity_type || '').trim() || DEFAULT_TARGET_ENTITY,
                description: form.description,
                is_active: form.is_active,
                tags: form.tags,
            }

            try {
                let res
                if (state.isEdit) {
                    res = await updateWorkflow(form.uuid, payload)
                } else {
                    res = await storeWorkflow({
                        ...payload,
                        nodes: [],
                    })
                }

                if (res?.status === 'success') {
                    iAlert(res)
                    closeForm()
                    emit('saved', {
                        workflow: res?.data || null,
                        slug: payload.slug,
                        mode: state.isEdit ? 'edit' : 'create',
                        openBuilder: !state.isEdit,
                    })
                } else {
                    iAlert(res, true)
                }
            } catch (error) {
                iAlert(error, true)
            } finally {
                state.loading = false
            }
        })
        .catch(finishFailed)
}

defineExpose({
    openCreate,
    openEdit,
    closeForm,
})
</script>
