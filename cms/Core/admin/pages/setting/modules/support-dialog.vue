<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-modal
        :open="open"
        centered
        :title="$t('text.modules.support.title', { module: entry?.label || entry?.name || '' })"
        width="min(720px, calc(100vw - 32px))"
        :mask-closable="!sending"
        :keyboard="!sending"
        :closable="!sending"
        :destroy-on-close="true"
        data-testid="support-dialog"
        @cancel="close"
    >
        <div class="flex max-h-[50vh] flex-col gap-4 overflow-y-auto pr-1" :aria-busy="loading">
            <p class="m-0 text-sm text-ink-muted">{{ $t('text.modules.support.intro') }}</p>

            <!-- What will be sent: always visible, also while the bundle loads. -->
            <a-alert
                type="warning"
                show-icon
                :message="$t('text.modules.support.warning.title')"
                :description="$t('text.modules.support.warning.body')"
                data-testid="support-warning"
            />

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ $t('text.modules.support.subject_label') }}</span>
                <a-input v-model:value="subject" :maxlength="200" :disabled="sending" data-testid="support-subject" />
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ $t('text.modules.support.description_label') }}</span>
                <a-textarea
                    v-model:value="description"
                    :rows="4"
                    :maxlength="5000"
                    :disabled="sending"
                    :placeholder="$t('text.modules.support.description_placeholder')"
                    data-testid="support-description"
                />
            </label>

            <section class="flex flex-col gap-3" data-testid="support-bundle">
                <h4 class="m-0 text-sm font-semibold">{{ $t('text.modules.support.bundle_title') }}</h4>

                <div v-if="loading" data-testid="support-loading"><a-skeleton active :paragraph="{ rows: 4 }" /></div>
                <div v-else-if="loadError" role="alert" class="flex flex-col items-start gap-2" data-testid="support-error">
                    <p class="m-0 text-sm text-danger">{{ loadError }}</p>
                    <i-button size="sm" data-testid="support-retry" @click="load">{{ $t('text.modules.install.retry') }}</i-button>
                </div>
                <template v-else>
                    <p class="m-0 text-xs text-ink-muted" data-testid="support-count">
                        {{ $t('text.modules.support.count', { kept: keptIds.length, total: lines.length }) }}
                    </p>
                    <div v-for="group in groups" :key="group.section" class="flex flex-col gap-2" data-testid="support-group">
                        <h5 class="m-0 text-xs font-medium text-ink-muted">{{ $t('text.modules.support.section.' + group.section) }}</h5>
                        <p v-if="!group.lines.length" class="m-0 text-sm text-ink-muted" :data-testid="group.section === 'log' ? 'support-no-errors' : undefined">
                            {{ $t('text.modules.support.empty.' + group.section) }}
                        </p>
                        <ul v-else class="m-0 flex list-none flex-col gap-2 p-0">
                            <li v-for="line in group.lines" :key="line.id" class="min-w-0 rounded-lg border border-border p-2" data-testid="support-line">
                                <a-checkbox v-model:checked="kept[line.id]" :disabled="sending" data-testid="support-line-check" class="flex w-full items-start [&>span:last-child]:min-w-0 [&>span:last-child]:flex-1">
                                    <span class="block min-w-0">
                                        <span v-if="line.key" class="block text-xs font-medium text-ink-muted">{{ keyLabel(line.key) }}</span>
                                        <!-- Long unbroken text (class names, tokens) wraps inside the line; the checkbox stays visible. -->
                                        <span class="block max-h-40 overflow-auto whitespace-pre-wrap text-xs [overflow-wrap:anywhere]" data-testid="support-line-text">{{ line.text }}</span>
                                    </span>
                                </a-checkbox>
                            </li>
                        </ul>
                    </div>
                </template>
            </section>
        </div>
        <!-- Outside the scrolling body, so a refusal is seen next to the Send button. -->
        <a-alert v-if="formError" class="mt-3" type="error" show-icon :message="formError" data-testid="support-form-error" />

        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <i-button :disabled="sending" data-testid="support-cancel" @click="close">{{ $t('text.modules.support.cancel') }}</i-button>
                <i-button variant="primary" :loading="sending" :disabled="!canSend" data-testid="support-send" @click="submit">
                    {{ $t('text.modules.support.send') }}
                </i-button>
            </div>
        </template>
    </a-modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'

/**
 * "Báo lỗi / Cần hỗ trợ" of one installed module.
 *
 * The site builds a masked diagnostic bundle; the Webmaster reads every line here, unticks what must not
 * leave the website and sends a subject, a description and the ids of the ticked lines. The browser never sends bundle
 * text: the site rebuilds the bundle and keeps only those ids. The warning about what will be sent is always shown
 * above the Send button. Nothing is sent until Send is pressed, and Send cannot run twice at the same time.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The module (name, label); an installed module. */
    entry: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const base = 'utilities/modules/'
const quiet = { silent: true }
const SECTIONS = ['environment', 'log', 'failed_job']
const ENV_KEYS = ['module_version', 'module_source', 'module_enabled', 'core_version', 'core_module_version', 'release', 'php_version', 'laravel_version', 'database', 'enabled_modules']

const { t } = useI18n()
const loading = ref(false)
const loadError = ref('')
const lines = ref([])
const kept = reactive({})
const subject = ref('')
const description = ref('')
const sending = ref(false)
const formError = ref('')
let token = 0

const groups = computed(() => SECTIONS.map((section) => ({ section, lines: lines.value.filter((line) => line.section === section) })))
const keptIds = computed(() => lines.value.filter((line) => kept[line.id]).map((line) => line.id))
const canSend = computed(() => !loading.value && !loadError.value && !sending.value && subject.value.trim() !== '' && description.value.trim() !== '')

/** Plain label of an environment fact; an unknown key shows as it is. */
const keyLabel = (key) => (ENV_KEYS.includes(key) ? t('text.modules.support.env.' + key) : key)

const errorCodeOf = (error) => {
    const body = error?.response?.data || {}
    const code = body.errorCode || body.error_code
    return typeof code === 'string' ? code : ''
}

/** Plain words for a refused send. */
const sendErrorText = (error) => {
    const keys = {
        MODULE_SUPPORT_NOT_INSTALLED: 'text.modules.support.error.not_installed',
        MODULE_SUPPORT_NOT_FOUND: 'text.modules.support.error.not_found',
        MODULE_SUPPORT_RENEWAL_REQUIRED: 'text.modules.support.error.renewal_required',
        MODULE_SUPPORT_INVALID: 'text.modules.support.error.invalid',
        MODULE_SUPPORT_RATE_LIMITED: 'text.modules.support.error.rate_limited',
        MODULE_SUPPORT_UNAVAILABLE: 'text.modules.support.error.unavailable',
    }
    const code = errorCodeOf(error)
    if (keys[code]) return t(keys[code])
    if (error?.response?.status === 429) return t(keys.MODULE_SUPPORT_RATE_LIMITED)
    if (error?.response?.status === 422) return t(keys.MODULE_SUPPORT_INVALID)
    return t('text.modules.support.error.send_failed')
}

/** Read the masked bundle from the site (nothing is sent) and tick every line. */
const load = async () => {
    const name = props.entry?.name
    if (!name) return
    const mine = ++token
    loading.value = true
    loadError.value = ''
    try {
        const answer = await ajax.get(`${base}${encodeURIComponent(name)}/support/preview`, quiet)
        if (mine !== token) return
        const list = Array.isArray(answer.data?.data?.lines) ? answer.data.data.lines : []
        lines.value = list
        for (const key of Object.keys(kept)) delete kept[key]
        for (const line of list) kept[line.id] = true
    } catch {
        if (mine === token) loadError.value = t('text.modules.support.load_failed')
    } finally {
        if (mine === token) loading.value = false
    }
}

const close = () => {
    if (sending.value) return
    emit('close')
}

/** Send the subject, the description and the ticked line ids; the first press blocks the next ones. */
const submit = async () => {
    if (sending.value) return
    if (!canSend.value) return
    sending.value = true
    formError.value = ''
    try {
        await ajax.post(
            `${base}${encodeURIComponent(props.entry.name)}/support`,
            { subject: subject.value.trim(), description: description.value.trim(), line_ids: keptIds.value },
            quiet,
        )
        message.success(t('text.modules.support.sent'))
        sending.value = false
        emit('close')
    } catch (error) {
        formError.value = sendErrorText(error)
        sending.value = false
    }
}

/** A freshly opened dialog (or another module) starts empty and reads its own bundle. */
watch(
    () => [props.open, props.entry?.name],
    ([open]) => {
        if (!open) return
        subject.value = ''
        description.value = ''
        formError.value = ''
        lines.value = []
        load()
    },
    { immediate: true },
)
</script>
