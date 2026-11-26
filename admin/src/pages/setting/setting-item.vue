<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div
        class="flex relative"
        :class="layout === 'vertical' ? 'flex-col items-start' : ' flex-col md:flex-row'"
    >
        <div
            class="w-full leading-8 text-blk-800 font-medium"
            :class="
                clsx(
                    layout === 'vertical' ? 'mb-0.5' : 'mb-0 md:w-64 pr-3',
                    layout === 'horizontal' ? 'text-right' : 'text-left'
                )
            "
        >
            {{ $t(item.input_setting.title) }}
            <span v-if="item.input_setting.required" class="text-rose-500 pl-1.5">*</span>
        </div>
        <div class="flex-1 space-y-1 w-full relative">
            <span v-if="isLoading" class="absolute inset-0 bg-white/40 z-10 cursor-not-allowed">
                <i-icon class="absolute inset-0 m-auto size-6 animate-spin" icon="loading" />
            </span>

            <a-input-password
                v-if="isMaskedSecret && !isSelectItem"
                v-model:value="innerValue"
                :name="item.name"
                :placeholder="hasStoredSecret ? t('text.setting.secret_value_set_placeholder') : undefined"
                autocomplete="new-password"
                data-testid="setting-secret-input"
                @change="debouncedSecretSync"
            />
            <field-image
                v-else-if="item.type === 'image'"
                v-model="innerValue"
                @change="immediateSync"
            />
            <field-text
                v-else-if="item.type === 'textarea'"
                v-model="innerValue"
                :name="item.name"
                :required="item.input_setting.required"
                :rows="item?.input_setting?.rows"
                :setting="item"
                no-style
                @change="immediateSync"
            />
            <field-boolean
                v-else-if="item.type === 'boolean'"
                v-model="innerValue"
                :name="item.name"
                :required="item.input_setting.required"
                :rows="item?.input_setting?.rows"
                :setting="item"
                no-style
                @change="immediateSync"
            />
            <field-input
                v-else-if="
                    ['text', 'email', 'password', 'number', 'url', 'tel'].includes(item.type) &&
                    item.input_setting.options.length < 1 &&
                    item.name !== 'VITE_TEMPLATE_FOLDER'
                "
                v-model="innerValue"
                :name="item.name"
                :required="item.input_setting.required"
                :setting="item"
                no-style
                @change="immediateSync"
            />
            <field-select
                v-else-if="isSelectItem"
                v-model="innerValue"
                :name="item.name"
                :options="
                    item.name === 'VITE_TEMPLATE_FOLDER'
                        ? (settings?.template || []).map((t) => ({
                              value: t,
                              label: t,
                          }))
                        : item.input_setting.options
                "
                :required="item.input_setting.required"
                :setting="item"
                no-style
                @change="immediateSync"
            />
            <p
                v-if="hasStoredSecret && isSelectItem"
                class="text-xs text-gray-600"
                data-testid="setting-secret-hint"
            >
                {{ t('text.setting.secret_value_set_placeholder') }}
            </p>
            <div v-if="canClearSecret">
                <a-popconfirm
                    :cancel-text="$t('nav.b.cancel')"
                    :disabled="isClearing"
                    :ok-text="t('text.setting.secret_clear')"
                    :overlay-style="{ maxWidth: 'min(320px, calc(100vw - 32px))' }"
                    :title="t('text.setting.secret_clear_confirm')"
                    ok-type="danger"
                    @confirm="clearSecret"
                >
                    <a-button
                        :disabled="isClearing"
                        class="!px-0"
                        danger
                        data-testid="setting-secret-clear"
                        html-type="button"
                        size="small"
                        type="link"
                    >
                        {{ t('text.setting.secret_clear') }}
                    </a-button>
                </a-popconfirm>
            </div>
            <p class="text-xs text-gray-500/90" v-html="$t(item.input_setting.description)" />
        </div>

        <div v-if="isEditable && rule('setting.update')" class="absolute -right-6 top-0 z-10">
            <span class="text-blue-500" role="button" @click="editSetting(item.setting_key)">
                <i-icon class="size-4" icon="edit" />
            </span>
        </div>
    </div>
</template>
<script setup>
import { rule } from '@/mixins'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { debounce } from 'lodash-es'
import { useSettings } from '@/hooks'
import clsx from 'clsx'
import ajax from '@/ajax'
import { message } from 'ant-design-vue'

/** Value the settings API returns for a secret that is stored (the real value is never sent). */
const MASKED_SECRET_VALUE = '********'

const { t } = useI18n()

const { data: settings } = useSettings({})
const { modelValue, item, layout, isEditable, isImmediateSync } = defineProps({
    modelValue: { default: undefined },
    item: { type: Object, default: {} },
    layout: { type: String, default: 'horizontal' },
    isEditable: { type: Boolean, default: true },
    isImmediateSync: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'edit'])
import { useAppRuntime } from '@/runtime'
const { fetchAndCacheGlobalSettings } = useAppRuntime()

// A masked secret starts empty: the operator types a new value to replace it, and an untouched
// field submits '' which the backend skips, so the stored secret is kept.
const isMaskedSecret = computed(() => item?.value === MASKED_SECRET_VALUE)
const isSelectItem = computed(
    () => (item?.input_setting?.options?.length ?? 0) > 0 || item?.name === 'VITE_TEMPLATE_FOLDER'
)
// Set once the stored secret was cleared here: the row then reads as empty until the list is loaded again.
const isCleared = ref(false)
const hasStoredSecret = computed(() => isMaskedSecret.value && !isCleared.value)
const canClearSecret = computed(() => hasStoredSecret.value && rule('setting.update'))
const unmaskValue = (value) => (value === MASKED_SECRET_VALUE ? '' : value)

const innerValue = ref(unmaskValue(modelValue ?? null))
const editSetting = (name) => {
    emit('edit', name)
}

const formatValueByInputType = ($value, $type) => {
    switch ($type) {
        case 'boolean':
            if ([true, 'true', 1, '1'].includes($value)) {
                return true
            }
            if ([false, 'false', 0, '0', null, undefined].includes($value)) {
                return false
            }
            return Boolean($value)
        case 'number':
            return Number($value)
        case 'text':
        case 'email':
        case 'password':
        case 'url':
        case 'tel':
            return String($value || '')
        default:
            return $value
    }
}

const isLoading = ref(false)
// Quick-sync a replaced secret only once the operator has typed something; empty keeps the stored value.
const debouncedSecretSync = debounce(() => {
    if (innerValue.value) {
        immediateSync()
    }
}, 300)
const immediateSync = async () => {
    if (!isImmediateSync) return
    isLoading.value = true
    await ajax
        .put(`setting/${item.id}/quick`, {
            value: innerValue.value,
            type: item.type,
        })
        .then(() => {
            // A secret typed after a clear is stored again.
            if (isMaskedSecret.value && innerValue.value) isCleared.value = false
        })
        .finally(() => {
            fetchAndCacheGlobalSettings().finally(() => {
                isLoading.value = false
            })
        })
}

// Empty a stored secret. A plain save cannot do it: '' and the mask both keep the stored value.
const isClearing = ref(false)
const clearSecret = async () => {
    if (isClearing.value) return
    isClearing.value = true
    try {
        await ajax.put(`setting/${item.id}/quick`, { clear: true })
        isCleared.value = true
        innerValue.value = ''
        message.success(t('text.setting.secret_cleared'))
        fetchAndCacheGlobalSettings().catch(() => {})
    } catch (err) {
        // The ajax layer already shows the server's message; only an unreported failure needs one here.
        if (!err?.reported) message.error(t('text.setting.secret_clear_failed'))
    } finally {
        isClearing.value = false
    }
}

watch(
    () => modelValue,
    (val) => {
        innerValue.value = unmaskValue(val ?? null)
    },
    { deep: true }
)

watch(
    () => innerValue.value,
    (val) => {
        emit('update:modelValue', val)
    },
    { deep: true }
)

watch(
    () => item,
    (newItem) => {
        if (newItem) {
            // A freshly loaded row is the truth again.
            isCleared.value = false
            if (newItem.value === MASKED_SECRET_VALUE) {
                innerValue.value = ''
                return
            }
            innerValue.value =
                modelValue === undefined
                    ? formatValueByInputType(newItem.value ?? null, newItem.type || 'text')
                    : unmaskValue(modelValue)
        }
    },
    { immediate: true, deep: true }
)
</script>
