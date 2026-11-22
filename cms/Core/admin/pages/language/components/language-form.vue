<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form
        id="language-form"
        ref="formRef"
        :model="form"
        layout="vertical"
        @finish="submit"
        @finish-failed="finishFailed"
    >
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6">
            <a-form-item :label="$t('validation.attributes.name')" :rules="nameRules" name="name">
                <a-input
                    v-model:value="form.name"
                    :maxlength="100"
                    :placeholder="$t('validation.attributes.name')"
                />
            </a-form-item>
            <a-form-item
                :extra="$t('text.language.path_help')"
                :label="$t('validation.attributes.path')"
                :rules="pathRules"
                name="path"
            >
                <a-input
                    :maxlength="10"
                    :placeholder="'vi'"
                    :value="form.path"
                    @update:value="onPathInput"
                >
                    <template #addonBefore>/</template>
                </a-input>
            </a-form-item>
            <a-form-item
                :extra="$t('text.language.path_alias_help')"
                :label="$t('validation.attributes.path_alias')"
                :rules="pathAliasRules"
                name="path_alias"
            >
                <a-input
                    v-model:value="form.path_alias"
                    :maxlength="10"
                    :placeholder="'vi-VN'"
                />
            </a-form-item>
            <a-form-item
                :extra="$t('text.language.code_help')"
                :label="$t('validation.attributes.code')"
                name="code"
            >
                <a-input
                    :maxlength="10"
                    :placeholder="'VN'"
                    :value="form.code"
                    @update:value="onCodeInput"
                />
            </a-form-item>
            <a-form-item :label="$t('validation.attributes.currency')" name="currency">
                <a-input
                    v-model:value="form.currency"
                    :maxlength="10"
                    placeholder="đ / $ / VND"
                />
            </a-form-item>
            <a-form-item :label="$t('validation.attributes.time_zone')" name="time_zone">
                <a-select
                    v-model:value="form.time_zone"
                    :options="timeZoneOptions"
                    :placeholder="$t('validation.attributes.time_zone')"
                    allow-clear
                    show-search
                />
            </a-form-item>
            <a-form-item
                :extra="$t('text.language.flag_help')"
                :label="$t('text.config.flag')"
                name="flag"
            >
                <a-select
                    v-model:value="form.flag"
                    :options="flagOptions"
                    :placeholder="$t('text.config.flag')"
                    allow-clear
                    show-search
                >
                    <template #option="{ value, label }">
                        <span class="inline-flex items-center gap-2">
                            <img
                                :alt="label"
                                :src="`${apiUrl}images/flags/4x3/${value}`"
                                class="h-3.5 w-auto"
                            />
                            <span>{{ label }}</span>
                        </span>
                    </template>
                </a-select>
            </a-form-item>
            <a-form-item :label="$t('validation.attributes.status')" name="status">
                <a-radio-group v-model:value="form.status">
                    <a-radio
                        v-for="item in statusOptions"
                        :key="item.key"
                        :value="item.key"
                    >
                        {{ isVi ? item.name : item.name_alias }}
                    </a-radio>
                </a-radio-group>
            </a-form-item>
            <a-form-item
                :extra="$t('text.language.locked_help')"
                :label="$t('text.config.locked')"
                name="is_locked"
            >
                <a-switch v-model:checked="form.is_locked" :aria-label="$t('text.config.locked')" />
            </a-form-item>
            <a-form-item :label="$t('text.use_for')">
                <div class="flex flex-wrap gap-4">
                    <label
                        v-for="p in platforms"
                        :key="p.key"
                        class="inline-flex items-center gap-2 text-sm"
                    >
                        <a-switch
                            v-model:checked="form[`for_${p.key}`]"
                            :aria-label="$t('text.language.use_for_platform', { platform: $t(p.label) })"
                            :data-column="`for_${p.key}`"
                            @change="!form[`for_${p.key}`] && (form[`default_${p.key}`] = false)"
                        />
                        {{ $t(p.label) }}
                    </label>
                </div>
            </a-form-item>
            <a-form-item :extra="$t('text.language.default_help')" :label="$t('text.default')">
                <div class="flex flex-wrap gap-4">
                    <a-tooltip
                        v-for="p in platforms"
                        :key="p.key"
                        :title="
                            form[`for_${p.key}`] ? '' : $t('text.language.default_requires_platform')
                        "
                    >
                        <label class="inline-flex items-center gap-2 text-sm">
                            <a-switch
                                v-model:checked="form[`default_${p.key}`]"
                                :aria-label="$t('text.language.default_for_platform', { platform: $t(p.label) })"
                                :data-column="`default_${p.key}`"
                                :disabled="!form[`for_${p.key}`]"
                            />
                            {{ $t(p.label) }}
                        </label>
                    </a-tooltip>
                </div>
            </a-form-item>
        </div>
    </a-form>
</template>

<script setup>
import { computed, defineComponent, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import { finishFailed } from '@/mixins'
import { apiUrl } from '@/config'
import { useEnumerates, useLanguageFlags } from '@/hooks'
import { useAppRuntime } from '@/runtime'

defineComponent({
    name: 'LanguageForm',
})

const { t } = useI18n()
const { data: statuses } = useEnumerates()
const { data: flags } = useLanguageFlags()
const { locale } = useAppRuntime()
const isVi = computed(() => locale?.value === 'vi')

/** Status options deduplicated by bare key (`status_published` and `published` are one option). */
const statusOptions = computed(() => {
    const seen = new Map()
    for (const s of statuses.value || []) {
        if (s.type !== 'status') continue
        const key = String(s.enum_key).replace(/^status_/, '')
        if (!seen.has(key)) seen.set(key, { key, name: s.name, name_alias: s.name_alias })
    }
    return [...seen.values()]
})

const btnState = inject('btnState')
const form = inject('form')
const formRef = inject('formRef')
const submitForm = inject('submitForm')

const platforms = [
    { key: 'web', label: 'text.language.platform.web' },
    { key: 'adm', label: 'text.language.platform.adm' },
    { key: 'app', label: 'text.language.platform.app' },
]

const PATH_PATTERN = /^[a-z]{2,3}(-[a-z0-9]{2,8})?$/
const PATH_ALIAS_PATTERN = /^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/

const label = (key) => t(`validation.attributes.${key}`)
const required = (key) => ({
    required: true,
    trigger: ['blur', 'change'],
    message: t('validation.required', { a: label(key) }),
})
const pattern = (key, regex) => ({
    pattern: regex,
    trigger: ['blur', 'change'],
    message: t('validation.regex', { a: label(key) }),
})
const nameRules = computed(() => [required('name')])
const pathRules = computed(() => [required('path'), pattern('path', PATH_PATTERN)])
// Optional: the pattern only applies when a value is present.
const pathAliasRules = computed(() => [pattern('path_alias', PATH_ALIAS_PATTERN)])

/** Keep the path as a lowercase slug while typing. */
const onPathInput = (value) => {
    form.value.path = (value || '').toLowerCase()
}
/** Keep the country code uppercase while typing. */
const onCodeInput = (value) => {
    form.value.code = (value || '').toUpperCase()
}

const FALLBACK_TIME_ZONES = [
    'Asia/Ho_Chi_Minh',
    'Asia/Bangkok',
    'Asia/Singapore',
    'Asia/Tokyo',
    'Europe/London',
    'America/New_York',
    'UTC',
]
const timeZoneOptions = computed(() => {
    let zones =
        typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function'
            ? [...Intl.supportedValuesOf('timeZone')]
            : [...FALLBACK_TIME_ZONES]
    if (!zones.includes('UTC')) zones.push('UTC')
    if (form.value.time_zone && !zones.includes(form.value.time_zone)) {
        zones.push(form.value.time_zone)
    }
    return zones.map((zone) => ({ value: zone, label: zone }))
})

const flagOptions = computed(() => {
    const files = (flags.value || []).map((f) => f.file)
    // Keep a saved flag visible even when its file is no longer listed.
    if (form.value.flag && !files.includes(form.value.flag)) files.unshift(form.value.flag)
    return files.map((file) => ({ value: file, label: file }))
})

/** Validate, then hand over to the shared submit (guarded against double submit). */
const submit = async () => {
    if (btnState.value) {
        return
    }
    try {
        await formRef.value?.validate()
    } catch {
        return
    }
    submitForm()
}

defineExpose({ submit })
</script>
