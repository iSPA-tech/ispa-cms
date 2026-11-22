/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import { computed } from 'vue'
import { Modal, message } from 'ant-design-vue'
import ajax from '@/ajax'

export const BULK_SELECTION_LIMIT = 20

const resolveMessage = (value, fallback) => {
    if (typeof value === 'function') {
        return value()
    }

    if (typeof value === 'string' && value.trim().length) {
        return value
    }

    return fallback
}

export const useBulkApi = (resource, action) => {
    const resolvedResource = typeof resource === 'string' ? resource.trim() : ''
    const resolvedAction = typeof action === 'string' ? action.trim() : ''

    return async (payload = {}) => {
        if (!resolvedResource || !resolvedAction) {
            return {
                status: 'error',
                message: 'Invalid bulk action request.',
            }
        }

        const normalizedPayload = payload && typeof payload === 'object' ? payload : {}
        const ids = Array.isArray(normalizedPayload.ids) ? normalizedPayload.ids : []

        // TODO: Confirm backend bulk endpoint availability for core bulk routes.
        return await ajax
            .post(
                `core/bulk/${encodeURIComponent(resolvedResource)}/${encodeURIComponent(
                    resolvedAction
                )}`,
                {
                    ...normalizedPayload,
                    ids,
                }
            )
            .then((res) => res.data || {})
    }
}

export const isRateLimitedError = (error) => Number(error?.response?.status) === 429

export const isDryRunUnsupportedError = (error) => {
    const status = Number(error?.response?.status)
    if (status !== 400 && status !== 422) {
        return false
    }

    const responseData = error?.response?.data || {}
    const message = String(responseData?.message || error?.message || '').toLowerCase()

    if (message.includes('dry_run') || message.includes('dry run')) {
        return true
    }

    if (responseData?.errors?.dry_run) {
        return true
    }

    return false
}

export const resolvePreviewCount = (response, fallbackIds = []) => {
    const payload = response?.data ?? response ?? {}
    const count =
        payload?.affected_count ?? payload?.affectedCount ?? payload?.affected ?? payload?.count

    if (Number.isFinite(count)) {
        return Number(count)
    }

    const eligibleIds = payload?.eligible_ids ?? payload?.eligibleIds
    if (Array.isArray(eligibleIds)) {
        return eligibleIds.length
    }

    return Array.isArray(fallbackIds) ? fallbackIds.length : 0
}

export const buildPreviewContent = (count, suffix) => {
    const safeCount = Number.isFinite(count) ? count : 0
    const itemLabel = safeCount === 1 ? 'item' : 'items'
    const base = `This action will affect ${safeCount} ${itemLabel}. Continue?`

    if (suffix) {
        return `${base} ${suffix}`
    }

    return base
}

export const useBulkSelection = (
    selectedRowKeys,
    { limit = BULK_SELECTION_LIMIT, messageApi = message, maxMessage = 'Max 20 items' } = {}
) => {
    const rowSelection = computed(() => ({
        selectedRowKeys: selectedRowKeys.value,
        onChange: (keys) => {
            if (limit && keys.length > limit) {
                const warnMessage = resolveMessage(maxMessage, 'Max 20 items')
                if (warnMessage && messageApi?.warning) {
                    messageApi.warning(warnMessage)
                }
                return
            }
            selectedRowKeys.value = keys
        },
    }))

    return { rowSelection }
}

export const runBulkActionWithPreview = async ({
    ids = [],
    changes,
    request,
    messageApi = message,
    modal = Modal,
    limit = BULK_SELECTION_LIMIT,
    maxMessage = 'Max 20 items',
    confirmTitle = 'Confirm action',
    confirmOkText = 'Confirm',
    confirmCancelText = 'Cancel',
    confirmOkType,
    confirmContent,
    confirmContentSuffix,
    successMessage,
    errorMessage,
    rateLimitMessage = 'Too many requests. Please try again.',
    onSuccess,
    onError,
    throwOnDryRunError = false,
} = {}) => {
    const resolvedIds = Array.isArray(ids) ? ids : []

    if (!resolvedIds.length || typeof request !== 'function') {
        return
    }

    if (limit && resolvedIds.length > limit) {
        const warnMessage = resolveMessage(maxMessage, 'Max 20 items')
        if (warnMessage && messageApi?.warning) {
            messageApi.warning(warnMessage)
        }
        return
    }

    let previewResponse
    try {
        previewResponse = await request({
            ids: resolvedIds,
            changes,
            dry_run: true,
        })
    } catch (error) {
        if (throwOnDryRunError && isDryRunUnsupportedError(error)) {
            throw error
        }

        if (typeof onError === 'function') {
            onError(error)
        }

        if (isRateLimitedError(error)) {
            const throttleMessage = resolveMessage(
                rateLimitMessage,
                'Too many requests. Please try again.'
            )
            if (throttleMessage && messageApi?.warning) {
                messageApi.warning(throttleMessage)
            }
            return
        }

        const responseMessage = error?.response?.data?.message
        if (responseMessage && messageApi?.error) {
            messageApi.error(responseMessage)
            return
        }

        if (errorMessage && messageApi?.error) {
            messageApi.error(errorMessage)
        }

        return
    }

    if (previewResponse?.status !== 'success') {
        if (typeof onError === 'function') {
            onError(previewResponse)
        }

        if (previewResponse?.message && messageApi?.error) {
            messageApi.error(previewResponse.message)
            return
        }

        if (errorMessage && messageApi?.error) {
            messageApi.error(errorMessage)
        }

        return
    }

    const previewCount = resolvePreviewCount(previewResponse, resolvedIds)
    const resolvedContent =
        typeof confirmContent === 'function'
            ? confirmContent(previewCount, previewResponse?.data ?? {})
            : confirmContent

    const content = resolvedContent ?? buildPreviewContent(previewCount, confirmContentSuffix)

    modal.confirm({
        title: confirmTitle,
        content,
        okText: confirmOkText,
        cancelText: confirmCancelText,
        okType: confirmOkType,
        onOk: async () => {
            let response
            try {
                response = await request({
                    ids: resolvedIds,
                    changes,
                    dry_run: false,
                })
            } catch (error) {
                if (isRateLimitedError(error)) {
                    const throttleMessage = resolveMessage(
                        rateLimitMessage,
                        'Too many requests. Please try again.'
                    )
                    if (throttleMessage && messageApi?.warning) {
                        messageApi.warning(throttleMessage)
                    }
                    if (typeof onError === 'function') {
                        onError(error)
                    }
                    return
                }

                const responseMessage = error?.response?.data?.message
                if (responseMessage && messageApi?.error) {
                    messageApi.error(responseMessage)
                    if (typeof onError === 'function') {
                        onError(error)
                    }
                    return
                }

                if (errorMessage && messageApi?.error) {
                    messageApi.error(errorMessage)
                    if (typeof onError === 'function') {
                        onError(error)
                    }
                }

                return
            }

            if (response?.status === 'success') {
                if (successMessage && messageApi?.success) {
                    messageApi.success(successMessage)
                }
                if (typeof onSuccess === 'function') {
                    onSuccess(response)
                }
                return
            }

            if (typeof onError === 'function') {
                onError(response)
            }

            if (response?.message && messageApi?.error) {
                messageApi.error(response.message)
                return
            }

            if (errorMessage && messageApi?.error) {
                messageApi.error(errorMessage)
            }
        },
    })
}
