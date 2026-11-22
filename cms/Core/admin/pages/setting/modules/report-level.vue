<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section class="i-box space-y-4" data-testid="modules-report-level">
        <div class="space-y-1">
            <h3 id="report-level-title" class="m-0 text-base font-semibold">{{ $t('text.modules.report.title') }}</h3>
            <p class="m-0 text-sm text-ink-muted">{{ $t('text.modules.report.intro') }}</p>
        </div>

        <div v-if="state.loading && !state.loaded" aria-busy="true" data-testid="report-level-loading">
            <a-skeleton active :paragraph="{ rows: 4 }" />
        </div>
        <div v-else-if="state.error" role="alert" class="space-y-2" data-testid="report-level-error">
            <p class="m-0 text-sm text-danger">{{ state.error }}</p>
            <i-button size="sm" data-testid="report-level-retry" @click="load">{{ $t('text.modules.install.retry') }}</i-button>
        </div>
        <template v-else-if="state.loaded">
            <a-alert v-if="!state.connected" type="info" show-icon :message="$t('text.modules.report.not_connected')" data-testid="report-level-not-connected" />
            <a-alert v-else-if="state.envLocked" type="info" show-icon :message="$t('text.modules.report.env_locked')" data-testid="report-level-env-locked" />

            <div role="radiogroup" aria-labelledby="report-level-title" class="flex min-w-0 flex-col gap-2">
                <label
                    v-for="level in levels"
                    :key="level"
                    class="flex min-w-0 cursor-pointer items-start gap-3 rounded-lg border p-3"
                    :class="[selected === level ? 'border-primary' : 'border-border', state.envLocked ? 'cursor-not-allowed opacity-70' : '']"
                    :data-testid="`report-level-option-${level}`"
                >
                    <input
                        v-model="selected"
                        type="radio"
                        name="report-level"
                        class="mt-1 h-4 w-4 shrink-0 accent-primary"
                        :value="level"
                        :disabled="state.envLocked || saving"
                        :data-testid="`report-level-radio-${level}`"
                    />
                    <span class="min-w-0 space-y-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">{{ $t(`text.modules.report.level.${level}.label`) }}</span>
                            <a-tag v-if="level === state.defaultLevel" class="m-0" data-testid="report-level-default">{{ $t('text.modules.report.default_tag') }}</a-tag>
                            <a-tag v-if="state.connected && level === state.level" color="processing" class="m-0" data-testid="report-level-current">
                                {{ $t('text.modules.report.current_tag') }}
                            </a-tag>
                        </span>
                        <span class="block break-words text-sm text-ink-muted">{{ $t(`text.modules.report.level.${level}.hint`) }}</span>
                    </span>
                </label>
            </div>

            <a-alert v-if="selected === 'off'" type="warning" show-icon :message="$t('text.modules.report.off_warning')" data-testid="report-level-off-warning" />
            <a-alert v-if="saveError" type="error" show-icon :message="saveError" data-testid="report-level-save-error" />

            <div class="flex flex-wrap items-center justify-end gap-2">
                <i-button :loading="preview.loading" data-testid="report-level-preview" @click="openPreview">
                    {{ $t('text.modules.report.preview') }}
                </i-button>
                <i-button
                    v-if="!state.envLocked"
                    variant="primary"
                    :loading="saving"
                    :disabled="!canSave"
                    data-testid="report-level-save"
                    @click="save"
                >
                    {{ $t('text.modules.report.save') }}
                </i-button>
            </div>
        </template>

        <a-modal
            :open="preview.open"
            centered
            :title="$t('text.modules.report.preview_title', { level: levelLabel(preview.level) })"
            width="min(760px, calc(100vw - 32px))"
            :footer="null"
            :destroy-on-close="true"
            data-testid="report-level-preview-dialog"
            @cancel="preview.open = false"
        >
            <div class="flex flex-col gap-3">
                <div v-if="preview.error" role="alert" class="space-y-2" data-testid="report-level-preview-error">
                    <p class="m-0 text-sm text-danger">{{ preview.error }}</p>
                    <i-button size="sm" data-testid="report-level-preview-retry" @click="openPreview">{{ $t('text.modules.install.retry') }}</i-button>
                </div>
                <p v-else-if="preview.json === null" class="m-0 text-sm text-ink-muted" data-testid="report-level-preview-none">
                    {{ $t('text.modules.report.preview_none') }}
                </p>
                <template v-else>
                    <p class="m-0 text-sm text-ink-muted">{{ $t('text.modules.report.preview_hint') }}</p>
                    <!-- Plain text: the report is shown exactly as it is sent, never rendered as markup. -->
                    <pre
                        class="m-0 max-h-[60vh] overflow-auto whitespace-pre rounded-lg border border-border bg-surface-muted p-3 text-xs"
                        data-testid="report-level-preview-json"
                    >{{ preview.json }}</pre>
                </template>
            </div>
        </a-modal>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import ajax from '@/ajax'

/**
 * "Gửi cho iSPA" of the modules page: the Webmaster chooses how much the daily site report sends to iSPA
 * Hub (off, basic, full) and previews the exact JSON the next report would send at the chosen level before saving.
 * A level set on the server cannot be changed here; an unconnected site sends nothing whatever is chosen.
 */
const LEVELS = ['off', 'basic', 'full']
const base = 'utilities/modules/report-level'
const quiet = { silent: true }

const { t } = useI18n()
const state = reactive({ loading: false, loaded: false, error: '', level: 'off', defaultLevel: 'basic', envLocked: false, connected: false })
const selected = ref('off')
const saving = ref(false)
const saveError = ref('')
const preview = reactive({ open: false, loading: false, error: '', level: 'off', json: null })
let previewToken = 0

const levels = computed(() => LEVELS)
const canSave = computed(() => !saving.value && !state.envLocked && LEVELS.includes(selected.value) && selected.value !== state.level)

/** Label of a level, or "-" for an unknown value. */
const levelLabel = (level) => (LEVELS.includes(level) ? t(`text.modules.report.level.${level}.label`) : '-')

/** Copy the server answer into the view; the radio follows the level in use. */
const apply = (data) => {
    state.level = LEVELS.includes(data?.level) ? data.level : 'off'
    state.defaultLevel = LEVELS.includes(data?.default) ? data.default : 'basic'
    state.envLocked = data?.env_locked === true
    state.connected = data?.connected === true
    selected.value = state.level
    state.loaded = true
}

/** Read the level in use. */
const load = async () => {
    state.loading = true
    state.error = ''
    try {
        const answer = await ajax.get(base, quiet)
        apply(answer.data?.data)
    } catch {
        state.error = t('text.modules.report.error.load')
    } finally {
        state.loading = false
    }
}

/** Save the chosen level; a second click while saving does nothing. */
const save = async () => {
    if (!canSave.value) return
    saving.value = true
    saveError.value = ''
    try {
        const answer = await ajax.put(base, { level: selected.value }, quiet)
        apply(answer.data?.data)
        message.success(t('text.modules.report.saved'))
    } catch (error) {
        const body = error?.response?.data || {}
        const code = body.errorCode || body.error_code
        saveError.value = code === 'REPORT_LEVEL_MANAGED_BY_ENV' ? t('text.modules.report.env_locked') : t('text.modules.report.error.save')
    } finally {
        saving.value = false
    }
}

/** Show the exact report of the chosen level (saved or not). */
const openPreview = async () => {
    const mine = ++previewToken
    const level = selected.value
    preview.loading = true
    preview.error = ''
    try {
        const answer = await ajax.get(`${base}/preview`, { ...quiet, params: { level } })
        if (mine !== previewToken) return
        const data = answer.data?.data || {}
        preview.level = LEVELS.includes(data.level) ? data.level : level
        preview.json = typeof data.json === 'string' ? data.json : null
    } catch {
        if (mine !== previewToken) return
        preview.level = level
        preview.json = null
        preview.error = t('text.modules.report.error.preview')
    } finally {
        if (mine === previewToken) {
            preview.loading = false
            preview.open = true
        }
    }
}

onMounted(load)
</script>
