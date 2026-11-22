<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Block or unblock one widget on the whole site. Blocking asks for a reason (other operators read it on the card);
    unblocking only confirms. Errors are shown in Vietnamese by `error_code`, never as the server text.
-->
<template>
    <a-modal
        :open="open"
        :width="480"
        @cancel="close"
    >
        <template #title>
            <span class="block break-words pr-8">
                {{ $t(blocked ? 'text.widget_library.unblock_title' : 'text.widget_library.block_title') }}
            </span>
        </template>
        <div class="flex flex-col gap-3">
            <p class="m-0 break-words text-sm font-semibold text-slate-900">{{ widgetLabel(widget) }}</p>
            <p class="m-0 text-sm text-slate-600">
                {{ $t(blocked ? 'text.widget_library.unblock_hint' : 'text.widget_library.block_hint') }}
            </p>
            <p v-if="blocked && widget?.blocked_reason" class="m-0 break-words text-sm text-slate-600">
                {{ $t('text.widget_library.block_reason') }}: {{ widget.blocked_reason }}
            </p>
            <div v-if="!blocked">
                <label class="mb-1 block text-sm font-medium text-slate-700" for="widget-block-reason">
                    {{ $t('text.widget_library.block_reason') }}
                </label>
                <a-textarea
                    id="widget-block-reason"
                    v-model:value="reason"
                    data-test="block-reason"
                    :maxlength="500"
                    :rows="3"
                />
                <p v-if="missingReason" class="m-0 mt-1 text-xs text-red-600" role="alert">
                    {{ $t('text.widget_library.block_reason_required') }}
                </p>
            </div>
        </div>
        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <i-button data-test="block-cancel" native-type="button" @click="close">
                    {{ $t('nav.b.cancel') }}
                </i-button>
                <i-button
                    data-test="block-confirm"
                    :disabled="loading"
                    native-type="button"
                    :variant="blocked ? 'primary' : 'tone-danger'"
                    @click="submit"
                >
                    {{ $t(blocked ? 'text.widget_library.action_unblock' : 'text.widget_library.action_block') }}
                </i-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { widgetLabel } from '@/libs/widget-names'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import { blockWidget, unblockWidget, widgetErrorCode } from '../../../widget-library-api'

defineOptions({ name: 'WidgetLibraryBlockDialog' })

const props = defineProps({
    open: { type: Boolean, default: false },
    // Library row being blocked or unblocked.
    widget: { type: Object, default: null },
})

// `done` carries {id, is_blocked, blocked_reason} of the changed widget.
const emit = defineEmits(['update:open', 'done'])

const { t } = useI18n()

const blocked = computed(() => props.widget?.is_blocked === true)
const reason = ref('')
const missingReason = ref(false)
const loading = ref(false)

watch(
    () => props.open,
    (open) => {
        if (open) {
            reason.value = ''
            missingReason.value = false
        }
    }
)

const close = () => emit('update:open', false)

const ERROR_COPY = {
    WIDGET_BLOCK_FORBIDDEN: 'text.widget_library.block_forbidden',
    WIDGET_NOT_FOUND: 'text.widget_library.not_found',
}

const submit = async () => {
    if (loading.value || !props.widget) return
    const text = reason.value.trim()
    if (!blocked.value && !text) {
        missingReason.value = true
        return
    }
    missingReason.value = false
    loading.value = true
    try {
        if (blocked.value) {
            await unblockWidget(props.widget.id)
            message.success(t('text.widget_library.unblocked_toast'))
            emit('done', { id: props.widget.id, is_blocked: false, blocked_reason: null })
        } else {
            await blockWidget(props.widget.id, text)
            message.success(t('text.widget_library.blocked_toast'))
            emit('done', { id: props.widget.id, is_blocked: true, blocked_reason: text })
        }
        close()
    } catch (error) {
        message.error(t(ERROR_COPY[widgetErrorCode(error)] ?? 'text.widget_library.action_failed'))
    } finally {
        loading.value = false
    }
}

defineExpose({ reason })
</script>
