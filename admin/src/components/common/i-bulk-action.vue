<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-blk-200/60 bg-blk-50/60 px-3 py-2"
    >
        <span v-if="showSelectedLabel" class="text-xs text-gray-600">
            {{ selectedLabel }}
        </span>
        <slot :disabled="isDisabled" name="summary" />
        <div class="flex flex-wrap items-center gap-2">
            <i-button
                v-for="action in actions"
                :key="action.key"
                :disabled="isDisabled"
                :loading="isActionPreviewLoading(action)"
                :variant="resolveActionVariant(action)"
                size="sm"
                @click="() => handleAction(action)"
            >
                <template v-if="hasStringIcon(action) || hasComponentIcon(action)" #icon>
                    <i-icon v-if="hasStringIcon(action)" :icon="action.icon" class="size-3" />
                    <component :is="action.icon" v-else class="size-3" />
                </template>
                {{ action.label }}
            </i-button>
            <slot :disabled="isDisabled" name="extra-actions" />
        </div>
    </div>

    <a-modal v-model:open="confirmOpen" :title="confirmTitle" @cancel="closeConfirmModal">
        <component :is="confirmModalContent" v-if="confirmModalContent" />
        <template #footer>
            <div class="flex flex-nowrap items-center justify-end gap-2">
                <i-button
                    :disabled="confirmSubmitting"
                    size="sm"
                    variant="default"
                    @click="closeConfirmModal"
                >
                    <template #icon>
                        <i-icon class="size-3" icon="ban" />
                    </template>
                    {{ confirmCancelText }}
                </i-button>

                <i-button
                    :class="confirmOkButtonClass"
                    :disabled="confirmOkDisabled"
                    :loading="confirmSubmitting"
                    :variant="confirmOkVariant"
                    size="sm"
                    @click="confirmRunAction"
                >
                    <template
                        v-if="confirmActionHasStringIcon || confirmActionHasComponentIcon"
                        #icon
                    >
                        <i-icon
                            v-if="confirmActionHasStringIcon"
                            :icon="confirmActionIcon"
                            class="size-3"
                        />
                        <component :is="confirmActionIcon" v-else class="size-3" />
                    </template>
                    {{ confirmOkText }}
                </i-button>
            </div>
        </template>
    </a-modal>

    <a-modal v-model:open="changeModalOpen" :title="changeModalTitle">
        <div class="space-y-3">
            <p v-if="showSelectedLabel" class="text-xs text-gray-600">
                {{ selectedLabel }}
            </p>
            <a-form class="space-y-3" layout="vertical">
                <a-form-item
                    v-for="field in changeModalFields"
                    :key="field.key"
                    :label="field.label"
                    :required="field.required"
                >
                    <a-select
                        v-if="field.type === 'select'"
                        v-model:value="changeForm[field.key]"
                        :allow-clear="field.allowClear !== false"
                        :mode="field.mode === 'multiple' ? 'multiple' : undefined"
                        :options="resolveFieldOptions(field)"
                        :placeholder="field.placeholder"
                        v-bind="resolveFieldProps(field)"
                    />
                    <a-input
                        v-else
                        v-model:value="changeForm[field.key]"
                        :placeholder="field.placeholder"
                        v-bind="resolveFieldProps(field)"
                    />
                </a-form-item>
            </a-form>
        </div>
        <template #footer>
            <div class="flex flex-nowrap items-center justify-end gap-2">
                <i-button size="sm" variant="default" @click="closeChangeModal">
                    {{ changeModalCancelText }}
                </i-button>
                <i-button
                    :disabled="isChangeSubmitDisabled"
                    :variant="changeModalOkVariant"
                    size="sm"
                    @click="submitChangeAction"
                >
                    {{ changeModalOkText }}
                </i-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { computed, defineComponent, h, ref, unref, watch } from 'vue'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import IButton from '@/components/i-button.vue'
import { iAlert } from '@/mixins'
import { isDryRunUnsupportedError, runBulkActionWithPreview, useBulkApi } from '@/hooks'

defineComponent({
    name: 'i-bulk-action',
})

const emit = defineEmits(['update:modelValue', 'success', 'error'])

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    actions: {
        type: Array,
        default: () => [],
    },
    baseActions: {
        type: Array,
        default: () => ['publish', 'unpublish', 'delete'],
    },
    resource: {
        type: String,
        default: '',
    },
    enablePreview: {
        type: Boolean,
        default: true,
    },
    previewOnly: {
        type: Boolean,
        default: false,
    },
    requests: {
        type: Object,
        default: () => ({}),
    },
    maxItems: {
        type: Number,
        default: 20,
    },
    statusField: {
        type: String,
        default: 'status',
    },
    publishValue: {
        type: String,
        default: 'status_published',
    },
    unpublishValue: {
        type: String,
        default: 'status_draft',
    },
    max: {
        type: Number,
        default: 20,
    },
    showSelectedLabel: {
        type: Boolean,
        default: true,
    },
    autoClear: {
        type: Boolean,
        default: true,
    },
    texts: {
        type: Object,
        default: () => ({}),
    },
})

// i18n helper for default labels.
const { t } = useI18n()
// Selected ids from v-model; always normalized to array.
const selectedIds = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []))

// Disable actions when nothing is selected.
const isDisabled = computed(() => selectedIds.value.length === 0)
// Resolve max selection cap (supports legacy max and maxItems).
const resolvedMax = computed(() => {
    if (Number.isFinite(props.maxItems) && props.maxItems !== 20) {
        return props.maxItems
    }

    if (Number.isFinite(props.max) && props.max !== 20) {
        return props.max
    }

    if (Number.isFinite(props.maxItems)) {
        return props.maxItems
    }

    if (Number.isFinite(props.max)) {
        return props.max
    }

    return 20
})

// Label shown for selected count.
const selectedLabel = computed(() => {
    const baseLabel = props.texts?.selectedLabel || t('text.bulk.selected')
    return `${baseLabel}: ${selectedIds.value.length}`
})

// Message shown when selection exceeds max.
const maxMessage = computed(() => {
    if (props.texts?.maxMessage) {
        return props.texts.maxMessage
    }
    return t('text.ui.bulk.max_items', { max: resolvedMax.value })
})

// Message shown when API rate limits.
const rateLimitMessage = computed(() => {
    return props.texts?.rateLimitMessage || t('text.ui.bulk.rate_limited')
})

// Warn and stop if selection is too large.
const warnMaxSelection = (count = selectedIds.value.length) => {
    if (resolvedMax.value && count > resolvedMax.value) {
        message.warning(maxMessage.value)
        return true
    }
    return false
}

// Emit updated selection to parent.
const emitSelection = (ids) => {
    emit('update:modelValue', ids)
}

// Show a user-friendly error message.
const showError = (errorMessage, fallbackMessage = t('text.ui.bulk.action_failed')) => {
    const messageText = errorMessage || fallbackMessage

    if (typeof messageText === 'string') {
        message.error(messageText)
        return
    }

    iAlert(messageText, true)
}

// Decide whether to clear selection after success.
const shouldAutoClear = (action) => {
    if (typeof action?.autoClear === 'boolean') {
        return action.autoClear
    }

    return props.autoClear
}

// Build payload for bulk request.
const resolvePayload = (action) => {
    if (typeof action?.buildPayload === 'function') {
        const payload = action.buildPayload(selectedIds.value)
        if (payload && typeof payload === 'object') {
            return payload
        }
    }

    return { ids: selectedIds.value }
}

// Default action order for base actions.
const baseActionOrder = ['publish', 'unpublish', 'delete']
// Resource must be present for default API helpers.
const hasResource = computed(
    () => typeof props.resource === 'string' && props.resource.trim().length > 0
)

// Resolve request function for a given action key.
const resolveRequest = (key) => {
    const override = props.requests?.[key]
    if (typeof override === 'function') {
        return override
    }

    if (hasResource.value) {
        return useBulkApi(props.resource, key)
    }

    return null
}

// Build status change payload for publish/unpublish.
const buildStatusPayload = (ids, value) => {
    if (!props.statusField) {
        return { ids }
    }

    return {
        ids,
        changes: {
            [props.statusField]: value,
        },
    }
}

// Build default action config (publish/unpublish/delete).
const buildBaseAction = (key) => {
    const request = resolveRequest(key)

    if (typeof request !== 'function') {
        return null
    }

    if (key === 'publish') {
        return {
            key: 'publish',
            label: t('nav.b.publish'),
            type: 'success',
            icon: 'eye',
            buildPayload: (ids) => buildStatusPayload(ids, props.publishValue),
            confirm: {
                title: t('text.bulk.publish-selected'),
                okText: t('nav.b.publish'),
                cancelText: t('nav.b.cancel'),
            },
            request,
        }
    }

    if (key === 'unpublish') {
        return {
            key: 'unpublish',
            label: t('nav.b.un-published'),
            icon: 'eye-slash',
            buildPayload: (ids) => buildStatusPayload(ids, props.unpublishValue),
            confirm: {
                title: t('text.bulk.unpublish-selected'),
                okText: t('nav.b.un-published'),
                cancelText: t('nav.b.cancel'),
            },
            request,
        }
    }

    if (key === 'delete') {
        return {
            key: 'delete',
            label: t('nav.b.delete'),
            type: 'danger',
            icon: 'delete',
            confirm: {
                title: t('text.bulk.delete-selected'),
                okText: t('nav.b.delete'),
                cancelText: t('nav.b.cancel'),
                okType: 'danger',
                contentSuffix: t('text.bulk.delete-selected-warning'),
            },
            request,
        }
    }

    return null
}

// Base actions provided by resource/requests.
const baseActions = computed(() => {
    const configured = Array.isArray(props.baseActions) ? props.baseActions : []

    return baseActionOrder
        .filter((key) => configured.includes(key))
        .map((key) => buildBaseAction(key))
        .filter(Boolean)
})

// Combined list of base + custom actions, keeping delete at the end.
const actions = computed(() => {
    const custom = Array.isArray(props.actions) ? props.actions : []
    const base = baseActions.value
    const deleteAction = base.find((action) => action?.key === 'delete')
    const baseWithoutDelete = base.filter((action) => action?.key !== 'delete')

    if (deleteAction) {
        return [...baseWithoutDelete, ...custom, deleteAction]
    }

    return [...baseWithoutDelete, ...custom]
})

// Detect string icon keys to render with i-icon.
const hasStringIcon = (action) => typeof action?.icon === 'string' && action.icon.length > 0

// Detect component icon entries for custom actions.
const hasComponentIcon = (action) => Boolean(action?.icon) && typeof action.icon !== 'string'

// Resolve button variant for toolbar actions.
const resolveActionVariant = (action) => {
    if (action?.variant) {
        return action.variant
    }

    if (action?.danger) {
        return 'danger'
    }

    const type = action?.type
    if (typeof type === 'string' && type.trim().length) {
        return type
    }

    return 'default'
}

// Track which action is running a preview request.
const previewSubmittingActionKey = ref('')

// Check if an action button should show preview loading.
const isActionPreviewLoading = (action) => previewSubmittingActionKey.value === action?.key

// Find action config by key (custom first).
const resolveActionConfig = (actionKey) => {
    const custom = Array.isArray(props.actions) ? props.actions : []
    const customMatch = custom.find((action) => action?.key === actionKey)
    if (customMatch) {
        return customMatch
    }
    return baseActions.value.find((action) => action?.key === actionKey)
}

// Find request function for action (override or default).
const resolveActionRequest = (actionKey, action) => {
    if (typeof action?.request === 'function') {
        return action.request
    }

    return resolveRequest(actionKey)
}

// Normalize confirm dialog config with safe defaults.
const resolveConfirmDefaults = (actionKey, action) => {
    const confirm = action?.confirm && action.confirm !== false ? action.confirm : {}
    const cancelText = confirm.cancelText || t('nav.b.cancel')
    const defaultWarning = t('text.bulk.delete-selected-warning')

    if (actionKey === 'publish') {
        return {
            title: confirm.title || t('text.bulk.publish-selected'),
            okText: confirm.okText || t('nav.b.publish'),
            cancelText,
            okType: confirm.okType,
            content: confirm.content,
            contentSuffix: confirm.contentSuffix,
        }
    }

    if (actionKey === 'unpublish') {
        return {
            title: confirm.title || t('text.bulk.unpublish-selected'),
            okText: confirm.okText || t('nav.b.un-published'),
            cancelText,
            okType: confirm.okType,
            content: confirm.content,
            contentSuffix: confirm.contentSuffix,
        }
    }

    if (actionKey === 'delete') {
        return {
            title: confirm.title || t('text.bulk.delete-selected'),
            okText: confirm.okText || t('nav.b.delete'),
            cancelText,
            okType: confirm.okType || 'danger',
            content: confirm.content,
            contentSuffix: confirm.contentSuffix || defaultWarning,
        }
    }

    return {
        title:
            confirm.title ||
            t('text.ui.bulk.confirm_action', {
                action: action?.label || t('text.ui.bulk.action_default'),
            }),
        okText: confirm.okText || t('nav.b.apply'),
        cancelText,
        okType: confirm.okType || (action?.danger ? 'danger' : undefined),
        content: confirm.content,
        contentSuffix: confirm.contentSuffix,
    }
}

// Normalize warning/error messages into string arrays.
const normalizeMessageList = (value) => {
    if (!value) {
        return []
    }

    if (Array.isArray(value)) {
        return value.filter(Boolean).map((item) => String(item))
    }

    if (typeof value === 'string') {
        const trimmed = value.trim()
        return trimmed.length ? [trimmed] : []
    }

    if (typeof value === 'object') {
        return Object.values(value)
            .flat()
            .filter(Boolean)
            .map((item) => String(item))
    }

    return [String(value)]
}

// Build confirmation content for preview/confirm modal.
const buildPreviewContent = (
    previewCount,
    previewData,
    ids,
    confirmContent,
    confirmContentSuffix
) => {
    const affectedValue = previewData?.affected_count ?? previewData?.affectedCount ?? previewCount
    const warnings = normalizeMessageList(previewData?.warnings ?? previewData?.warning)
    const errors = normalizeMessageList(previewData?.errors ?? previewData?.error)
    const customContent =
        typeof confirmContent === 'function'
            ? confirmContent(previewCount, previewData)
            : confirmContent

    const rows = [
        h('div', t('text.ui.bulk.selected_count', { count: ids.length })),
        Number.isFinite(affectedValue) ? h('div', t('text.ui.bulk.affected_count', { count: affectedValue })) : null,
        customContent ? h('div', customContent) : null,
        confirmContentSuffix ? h('div', confirmContentSuffix) : null,
        warnings.length
            ? h('div', [
                  h('div', t('text.ui.bulk.warnings')),
                  h(
                      'ul',
                      { class: 'list-disc pl-4' },
                      warnings.map((item) => h('li', item))
                  ),
              ])
            : null,
        errors.length
            ? h('div', [
                  h('div', t('text.ui.bulk.errors')),
                  h(
                      'ul',
                      { class: 'list-disc pl-4' },
                      errors.map((item) => h('li', item))
                  ),
              ])
            : null,
    ].filter(Boolean)

    return h('div', { class: 'space-y-2' }, rows)
}

// Resolve confirm button styles for common actions with overrides.
const resolveOkButtonStyle = (actionKey, confirmConfig, action) => {
    const overrides = action?.confirm || {}
    let okButtonType = 'primary'
    let okDanger = false
    let okButtonClass = ''

    if (actionKey === 'publish') {
        okButtonClass = '!bg-emerald-600 !border-emerald-600 hover:!bg-emerald-700'
    } else if (actionKey === 'unpublish') {
        okButtonClass = '!bg-amber-500 !border-amber-500 hover:!bg-amber-600'
    } else if (actionKey === 'delete') {
        okDanger = true
        okButtonClass = '!bg-rose-600 !border-rose-600 hover:!bg-rose-700'
    }

    if (confirmConfig?.okType && confirmConfig.okType !== 'danger') {
        okButtonType = confirmConfig.okType
    } else if (confirmConfig?.okType === 'danger') {
        okDanger = true
    }

    if (typeof overrides.okButtonType === 'string') {
        okButtonType = overrides.okButtonType
    }

    if (typeof overrides.okButtonClass === 'string') {
        okButtonClass = overrides.okButtonClass
    }

    if (typeof overrides.okDanger === 'boolean') {
        okDanger = overrides.okDanger
    }

    return { okButtonType, okDanger, okButtonClass }
}

// Extract ids/changes/extras from payload.
const normalizeExecutionPayload = (action, payloadOverride) => {
    const resolvedPayload =
        payloadOverride && typeof payloadOverride === 'object'
            ? payloadOverride
            : resolvePayload(action)
    const payload = resolvedPayload && typeof resolvedPayload === 'object' ? resolvedPayload : {}
    const ids = Array.isArray(payload.ids) ? payload.ids : selectedIds.value
    const changes = payload.changes
    const extras = { ...payload }
    delete extras.ids
    delete extras.changes
    delete extras.dry_run

    return { ids, changes, extras }
}

// Execute action immediately (no preview).
const executeDirect = async ({
    actionKey,
    action,
    ids,
    changes,
    request,
    extras,
    includeDryRun = true,
}) => {
    const buildPayload = (withDryRun) => {
        const payload = { ids, changes }
        if (withDryRun) {
            payload.dry_run = false
        }
        return { ...extras, ...payload }
    }

    try {
        const result = await request(buildPayload(includeDryRun))

        if (result?.status === 'success') {
            emit('success', {
                action: actionKey,
                actionKey,
                ids,
                response: result,
            })
            if (shouldAutoClear(action)) {
                emitSelection([])
            }
            return true
        }

        emit('error', { action: actionKey, actionKey, ids, error: result })
        showError(result?.message)
    } catch (error) {
        if (includeDryRun && isDryRunUnsupportedError(error)) {
            try {
                const result = await request(buildPayload(false))

                if (result?.status === 'success') {
                    emit('success', {
                        action: actionKey,
                        actionKey,
                        ids,
                        response: result,
                    })
                    if (shouldAutoClear(action)) {
                        emitSelection([])
                    }
                    return true
                }

                emit('error', { action: actionKey, actionKey, ids, error: result })
                showError(result?.message)
                return false
            } catch (fallbackError) {
                if (Number(fallbackError?.response?.status) === 429) {
                    message.warning(rateLimitMessage.value)
                    emit('error', { action: actionKey, actionKey, ids, error: fallbackError })
                    return false
                }

                const errorMessage =
                    fallbackError?.response?.data?.message || fallbackError?.message
                showError(errorMessage)
                emit('error', { action: actionKey, actionKey, ids, error: fallbackError })
                return false
            }
        }

        if (Number(error?.response?.status) === 429) {
            message.warning(rateLimitMessage.value)
            emit('error', { action: actionKey, actionKey, ids, error })
            return false
        }

        const errorMessage = error?.response?.data?.message || error?.message
        showError(errorMessage)
        emit('error', { action: actionKey, actionKey, ids, error })
    }

    return false
}

const confirmOpen = ref(false)
const confirmTitle = ref('')
const confirmOkText = ref('')
const confirmCancelText = ref('')
const confirmContent = ref(null)
const confirmOnOk = ref(null)
const confirmSubmitting = ref(false)
const confirmActionKey = ref('')
const confirmAction = ref(null)
const confirmConfig = ref(null)
const confirmActionIcon = computed(() => confirmAction.value?.icon)
const confirmActionHasStringIcon = computed(() => hasStringIcon(confirmAction.value))
const confirmActionHasComponentIcon = computed(() => hasComponentIcon(confirmAction.value))

const confirmOkStyle = computed(() =>
    resolveOkButtonStyle(confirmActionKey.value, confirmConfig.value, confirmAction.value)
)
const confirmOkButtonClass = computed(() => confirmOkStyle.value.okButtonClass)
const confirmOkDanger = computed(() => confirmOkStyle.value.okDanger)
const confirmOkDisabled = computed(() => confirmSubmitting.value)
const confirmOkVariant = computed(() => {
    const okType = confirmOkStyle.value.okButtonType
    if (confirmOkDanger.value || confirmConfig.value?.okType === 'danger' || okType === 'danger') {
        return 'danger'
    }

    if (okType && okType !== 'primary') {
        return okType
    }
    if (confirmActionKey.value === 'publish') {
        return 'success'
    }
    if (confirmActionKey.value === 'unpublish') {
        return 'warning'
    }
    if (confirmActionKey.value === 'delete' || confirmAction.value?.danger) {
        return 'danger'
    }
    return 'primary'
})

const normalizeConfirmContent = (content) => {
    if (content === null || content === undefined) {
        return null
    }

    if (typeof content === 'string' || typeof content === 'number') {
        return h('div', String(content))
    }

    return content
}

const confirmModalContent = computed(() => {
    const contentNode = normalizeConfirmContent(confirmContent.value)
    if (!contentNode) {
        return null
    }

    return {
        render: () => contentNode,
    }
})

const openConfirmModal = (config = {}) => {
    confirmTitle.value = config.title || ''
    confirmOkText.value = config.okText || t('nav.b.confirm')
    confirmCancelText.value = config.cancelText || t('nav.b.cancel')
    confirmContent.value = normalizeConfirmContent(config.content)
    confirmOnOk.value = typeof config.onOk === 'function' ? config.onOk : null
    confirmOpen.value = true
}

const closeConfirmModal = () => {
    confirmOpen.value = false
}

const resetConfirmState = () => {
    confirmTitle.value = ''
    confirmOkText.value = ''
    confirmCancelText.value = ''
    confirmContent.value = null
    confirmOnOk.value = null
    confirmSubmitting.value = false
    confirmActionKey.value = ''
    confirmAction.value = null
    confirmConfig.value = null
}

watch(
    () => confirmOpen.value,
    (open) => {
        if (!open) {
            resetConfirmState()
        }
    }
)

const confirmRunAction = async () => {
    if (confirmSubmitting.value) {
        return
    }

    if (!confirmOnOk.value) {
        closeConfirmModal()
        return
    }

    confirmSubmitting.value = true

    try {
        await confirmOnOk.value()
        closeConfirmModal()
    } catch (error) {
        // Keep modal open if confirm action fails.
    } finally {
        confirmSubmitting.value = false
    }
}

const confirmModal = {
    confirm: openConfirmModal,
}

// Execute action with preview/dry_run and fallback confirm.
const executeBulk = async (actionKey, payloadOverride) => {
    const action = resolveActionConfig(actionKey)
    if (!action) {
        return
    }

    const { ids, changes, extras } = normalizeExecutionPayload(action, payloadOverride)

    if (!Array.isArray(ids) || ids.length === 0) {
        return
    }

    if (warnMaxSelection(ids.length)) {
        return
    }

    const request = resolveActionRequest(actionKey, action)
    if (typeof request !== 'function') {
        return
    }

    const requestWithExtras = async (payload = {}) => {
        const normalizedPayload = payload && typeof payload === 'object' ? payload : {}
        const merged = { ...extras, ...normalizedPayload }
        if (!Array.isArray(merged.ids)) {
            merged.ids = ids
        }
        return await request(merged)
    }

    const resolvedConfirmConfig = resolveConfirmDefaults(actionKey, action)
    const shouldConfirm = action.confirm !== false && !action.skipConfirm
    const shouldPreview = props.enablePreview !== false && shouldConfirm

    if (shouldConfirm) {
        confirmActionKey.value = actionKey
        confirmAction.value = action
        confirmConfig.value = resolvedConfirmConfig

        if (shouldPreview) {
            const previewRequest = async (payload) => {
                if (props.previewOnly && payload?.dry_run === false) {
                    return {
                        status: 'success',
                        data: {
                            preview_only: true,
                        },
                    }
                }
                return await requestWithExtras(payload)
            }

            try {
                previewSubmittingActionKey.value = actionKey

                await runBulkActionWithPreview({
                    ids,
                    changes,
                    request: previewRequest,
                    messageApi: message,
                    modal: confirmModal,
                    confirmTitle: resolvedConfirmConfig.title,
                    confirmOkText: resolvedConfirmConfig.okText,
                    confirmCancelText: resolvedConfirmConfig.cancelText,
                    confirmOkType: resolvedConfirmConfig.okType,
                    confirmContent: (count, data) =>
                        buildPreviewContent(
                            count,
                            data || {},
                            ids,
                            resolvedConfirmConfig.content,
                            resolvedConfirmConfig.contentSuffix
                        ),
                    maxMessage: maxMessage.value,
                    rateLimitMessage: rateLimitMessage.value,
                    onSuccess: (response) => {
                        if (props.previewOnly) {
                            return
                        }
                        emit('success', {
                            action: actionKey,
                            actionKey,
                            ids,
                            response,
                        })
                        if (shouldAutoClear(action)) {
                            emitSelection([])
                        }
                    },
                    onError: (error) => {
                        emit('error', { action: actionKey, actionKey, ids, error })
                    },
                    throwOnDryRunError: true,
                })
            } catch (error) {
                if (!isDryRunUnsupportedError(error)) {
                    emit('error', { action: actionKey, actionKey, ids, error })
                    return
                }

                openConfirmModal({
                    title: resolvedConfirmConfig.title,
                    okText: resolvedConfirmConfig.okText,
                    cancelText: resolvedConfirmConfig.cancelText,
                    okType: resolvedConfirmConfig.okType,
                    content: buildPreviewContent(
                        ids.length,
                        {},
                        ids,
                        resolvedConfirmConfig.content,
                        resolvedConfirmConfig.contentSuffix
                    ),
                    onOk: async () => {
                        if (props.previewOnly) {
                            return
                        }
                        await executeDirect({
                            actionKey,
                            action,
                            ids,
                            changes,
                            request: requestWithExtras,
                            extras,
                            includeDryRun: false,
                        })
                    },
                })
            } finally {
                previewSubmittingActionKey.value = ''
            }

            return
        }

        openConfirmModal({
            title: resolvedConfirmConfig.title,
            okText: resolvedConfirmConfig.okText,
            cancelText: resolvedConfirmConfig.cancelText,
            okType: resolvedConfirmConfig.okType,
            content: buildPreviewContent(
                ids.length,
                {},
                ids,
                resolvedConfirmConfig.content,
                resolvedConfirmConfig.contentSuffix
            ),
            onOk: async () => {
                if (props.previewOnly) {
                    return
                }
                await executeDirect({
                    actionKey,
                    action,
                    ids,
                    changes,
                    request: requestWithExtras,
                    extras,
                    includeDryRun: false,
                })
            },
        })
        return
    }

    await executeDirect({
        actionKey,
        action,
        ids,
        changes,
        request: requestWithExtras,
        extras,
        includeDryRun: props.enablePreview === false,
    })
}

// Check if action should open a change modal.
const isChangeAction = (action) => action?.kind === 'change' && Array.isArray(action?.modal?.fields)

// Modal open state.
const changeModalOpen = ref(false)
// Active change action config.
const changeAction = ref(null)
// Form values for change modal.
const changeForm = ref({})

// Fields list for change modal form.
const changeModalFields = computed(() => changeAction.value?.modal?.fields || [])

// Resolve select options for modal fields.
const resolveFieldOptions = (field) => {
    const source = field?.options
    if (typeof source === 'function') {
        return source() || []
    }
    const resolved = unref(source)
    return Array.isArray(resolved) ? resolved : []
}

// Resolve extra props for modal fields.
const resolveFieldProps = (field) => {
    const source = field?.props
    if (typeof source === 'function') {
        return source() || {}
    }
    return source || {}
}

// Initialize change modal form values.
const resetChangeForm = (action) => {
    const fields = action?.modal?.fields || []
    const nextValues = {}

    fields.forEach((field) => {
        if (!field?.key) {
            return
        }

        if (Object.prototype.hasOwnProperty.call(field, 'defaultValue')) {
            nextValues[field.key] =
                typeof field.defaultValue === 'function' ? field.defaultValue() : field.defaultValue
            return
        }

        nextValues[field.key] = field.mode === 'multiple' ? [] : null
    })

    changeForm.value = nextValues
}

// Open modal and seed form values.
const openChangeModal = (action) => {
    changeAction.value = action
    resetChangeForm(action)
    changeModalOpen.value = true
}

// Close modal and clear state.
const closeChangeModal = () => {
    changeModalOpen.value = false
    changeAction.value = null
    changeForm.value = {}
}

// Determine if a field value should be treated as empty.
const isEmptyFieldValue = (value, field) => {
    if (field?.mode === 'multiple') {
        return !Array.isArray(value) || value.length === 0
    }

    if (value === null || value === undefined) {
        return true
    }

    if (typeof value === 'string') {
        return value.trim().length === 0
    }

    return false
}

// Disable submit when required fields are missing.
const isChangeSubmitDisabled = computed(() => {
    const fields = changeModalFields.value
    if (!fields.length) {
        return true
    }

    return fields.some(
        (field) => field?.required && isEmptyFieldValue(changeForm.value?.[field.key], field)
    )
})

// Modal title and button labels.
const changeModalTitle = computed(
    () => changeAction.value?.modal?.title || changeAction.value?.label || t('nav.b.change')
)
// Modal OK button text.
const changeModalOkText = computed(() => changeAction.value?.modal?.okText || t('nav.b.apply'))
// Modal cancel button text.
const changeModalCancelText = computed(() => changeAction.value?.modal?.cancelText || t('nav.b.cancel'))
// Modal OK button type (e.g., danger).
const changeModalOkType = computed(() => changeAction.value?.modal?.okType)
const changeModalOkVariant = computed(() => {
    if (changeModalOkType.value === 'danger') {
        return 'danger'
    }

    if (typeof changeModalOkType.value === 'string' && changeModalOkType.value.length) {
        return changeModalOkType.value
    }

    return 'primary'
})

// Submit change modal payload through bulk pipeline.
const submitChangeAction = async () => {
    if (!changeAction.value) {
        return
    }

    const payload =
        typeof changeAction.value.buildPayload === 'function'
            ? changeAction.value.buildPayload(selectedIds.value, changeForm.value)
            : {
                  ids: selectedIds.value,
                  changes: { ...changeForm.value },
              }

    await executeBulk(changeAction.value.key, payload)
    closeChangeModal()
}

// Handle click for any action button.
const handleAction = async (action) => {
    if (!action) {
        return
    }

    if (isChangeAction(action)) {
        openChangeModal(action)
        return
    }

    let payloadOverride

    if (typeof action.beforeRun === 'function') {
        const result = await action.beforeRun({
            selectedIds: selectedIds.value,
            action,
        })

        if (result === false || result === null) {
            return
        }

        if (result && typeof result === 'object') {
            payloadOverride = Object.prototype.hasOwnProperty.call(result, 'payload')
                ? result.payload
                : result
        }
    }

    await executeBulk(action.key, payloadOverride)
}

// Enforce max selection when selection or limit changes.
watch(
    () => [selectedIds.value.length, resolvedMax.value],
    () => {
        if (!resolvedMax.value || selectedIds.value.length <= resolvedMax.value) {
            return
        }

        const trimmed = selectedIds.value.slice(0, resolvedMax.value)
        emitSelection(trimmed)
        message.warning(maxMessage.value)
    }
)
// Toggle selected label display.
const showSelectedLabel = computed(() => props.showSelectedLabel)
</script>
