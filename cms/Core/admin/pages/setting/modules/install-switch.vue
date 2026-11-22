<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section ref="root" class="i-box space-y-2" data-testid="install-switch-card">
        <div v-if="state.loading && !state.loaded" aria-busy="true" data-testid="install-switch-loading">
            <a-skeleton active :title="false" :paragraph="{ rows: 1 }" />
        </div>
        <div v-else-if="!state.loaded" role="alert" class="flex flex-wrap items-center gap-2" data-testid="install-switch-load-error">
            <p class="m-0 text-sm text-danger">{{ state.error }}</p>
            <i-button size="sm" data-testid="install-switch-retry" @click="load">
                {{ $t('text.modules.install.retry') }}
            </i-button>
        </div>
        <template v-else>
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1 space-y-1">
                    <h3 id="module-install-switch-label" class="m-0 break-words text-base font-semibold">
                        {{ $t('text.modules.install.setting.label') }}
                    </h3>
                    <p
                        v-if="lockedKey"
                        id="module-install-switch-help"
                        class="m-0 flex items-start gap-1.5 text-sm text-ink-muted"
                        data-testid="install-switch-locked"
                    >
                        <LockOutlined class="mt-1 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 break-words">{{ $t(lockedKey) }}</span>
                    </p>
                    <p v-else id="module-install-switch-help" class="m-0 break-words text-sm text-ink-muted" data-testid="install-switch-help">
                        {{ $t(checked ? 'text.modules.install.setting.help_on' : 'text.modules.install.setting.help_off') }}
                    </p>
                </div>
                <a-switch
                    class="mt-0.5 shrink-0"
                    :checked="checked"
                    :disabled="Boolean(lockedKey) || state.saving"
                    :loading="state.saving"
                    aria-labelledby="module-install-switch-label"
                    aria-describedby="module-install-switch-help"
                    data-testid="install-switch"
                    @change="toggle"
                />
            </div>
            <p v-if="state.error" role="alert" class="m-0 break-words text-sm text-danger" data-testid="install-switch-error">
                {{ state.error }}
            </p>
        </template>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { LockOutlined } from '@ant-design/icons-vue'
import { getModuleInstallSetting, moduleErrorCodeOf, moduleErrorKey, updateModuleInstallSetting } from '@/libs/module'

/**
 * The "Cho phép cài module" switch of the Modules page (Webmaster only; the page mounts it for a Webmaster).
 *
 * The website installs modules only when the server does not forbid it, iSPA Hub does not forbid it and this switch
 * is on. When the server or the hub decides, the switch is read-only and one line says who decided. Every answer of
 * the API is passed up through `change`, so the page can switch its install actions off while installs are blocked.
 */
const emit = defineEmits(['change'])
const { t } = useI18n()

const root = ref(null)
const state = reactive({ loading: false, loaded: false, saving: false, error: '', info: null })

/** The switch shows what is in force: a server that switches installs on shows "on" even though the admin value is off. */
const checked = computed(() => Boolean(state.info?.enabled))

/** Who locked the switch, as a translation key; empty when the Webmaster decides. */
const lockedKey = computed(() => {
    const info = state.info
    if (!info) return ''
    if (info.state === 'forbidden_by_server' || (info.locked && info.server === 'off')) return 'text.modules.install.setting.locked_server_off'
    if (info.state === 'forbidden_by_hub') return 'text.modules.error.install_forbidden_by_hub'
    if (info.locked) return 'text.modules.install.setting.locked_server_on'
    return ''
})

const apply = (info) => {
    state.info = info
    state.loaded = true
    emit('change', info)
}

const messageOf = (error, fallbackKey) => {
    const key = moduleErrorKey(moduleErrorCodeOf(error))
    return t(key && key !== 'text.modules.error.unknown' ? key : fallbackKey)
}

const load = async () => {
    if (state.loading) return
    state.loading = true
    state.error = ''
    try {
        apply(await getModuleInstallSetting())
    } catch (error) {
        state.error = messageOf(error, 'text.modules.install.setting.load_failed')
    } finally {
        state.loading = false
    }
}

/**
 * Save the switch. A second click while the request runs sends nothing. A refusal because the server or the hub
 * decides is followed by a fresh read, so the switch shows who locked it.
 */
const toggle = async (value) => {
    if (state.saving || lockedKey.value) return
    state.saving = true
    state.error = ''
    try {
        apply(await updateModuleInstallSetting(Boolean(value)))
    } catch (error) {
        state.error = messageOf(error, 'text.modules.install.setting.save_failed')
        if (error?.response?.status === 409) {
            try {
                apply(await getModuleInstallSetting())
            } catch {
                // The refusal is already shown; the switch keeps its last known value.
            }
        }
    } finally {
        state.saving = false
    }
}

/** Bring the switch into view and put the keyboard on it (the page calls this from its "blocked" notices). */
const reveal = () => {
    const el = root.value
    if (!el) return
    const calm = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
    el.scrollIntoView?.({ behavior: calm ? 'auto' : 'smooth', block: 'center' })
    el.querySelector('button')?.focus?.({ preventScroll: true })
}

onMounted(load)
defineExpose({ reveal, load })
</script>
