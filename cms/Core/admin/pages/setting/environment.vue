<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-5xl mx-auto space-y-6" data-testid="environment-page">
        <template v-if="!allowed">
            <section v-if="accountReady" class="i-box" role="alert" data-testid="environment-not-allowed">
                <p class="m-0 text-sm text-ink-muted">{{ t('text.environment.not_allowed') }}</p>
            </section>
        </template>
        <template v-else>
            <section class="i-box space-y-1">
                <h3 class="m-0 text-sm font-semibold">{{ t('text.environment.title') }}</h3>
                <p class="m-0 text-sm text-ink-muted">{{ t('text.environment.description') }}</p>
                <p class="m-0 text-sm text-ink-muted" data-testid="environment-secret-notice">
                    {{ t('text.environment.secret_notice') }}
                </p>
                <p class="m-0 text-sm text-ink-muted" data-testid="environment-restart-notice">
                    {{ t('text.environment.restart_notice') }}
                </p>
            </section>

            <div v-if="loading" class="i-box space-y-2" role="status" data-testid="environment-loading">
                <a-skeleton active :paragraph="{ rows: 4 }" />
                <p class="m-0 text-sm text-ink-muted">{{ t('text.environment.loading') }}</p>
            </div>
            <a-alert
                v-else-if="loadError"
                type="error"
                show-icon
                :message="t('text.environment.load_error')"
                :description="t('text.environment.load_error_hint')"
                data-testid="environment-error"
            >
                <template #action>
                    <a-button size="small" data-testid="environment-retry" @click="load">
                        {{ t('text.environment.retry') }}
                    </a-button>
                </template>
            </a-alert>
            <section v-else-if="!groups.length" class="i-box" data-testid="environment-empty">
                <a-empty :description="t('text.environment.empty')" />
            </section>
            <template v-else>
                <a-alert
                    v-if="notice"
                    type="success"
                    show-icon
                    closable
                    :message="notice"
                    data-testid="environment-success"
                    @close="notice = ''"
                />
                <section v-for="group in groups" :key="group.name" class="i-box space-y-3">
                    <h4 class="m-0 text-sm font-semibold">{{ group.title }}</h4>
                    <div
                        v-for="item in group.items"
                        :key="item.key"
                        class="space-y-2 border-t border-gray-200 pt-3 first:border-t-0 first:pt-0"
                        :data-testid="`env-row-${item.key}`"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="break-words text-sm font-medium">{{ labelOf(item) }}</div>
                            </div>
                            <a-tag
                                v-if="item.secret"
                                class="m-0"
                                :color="item.has_value ? 'green' : 'default'"
                                :data-testid="`env-badge-${item.key}`"
                            >
                                {{ item.has_value ? t('text.environment.status_set') : t('text.environment.status_not_set') }}
                            </a-tag>
                        </div>

                        <!-- Secret: write-only. The value is never shown, the input only opens on Replace. -->
                        <template v-if="item.secret">
                            <form
                                v-if="editing === item.key"
                                class="flex flex-wrap items-center gap-2"
                                autocomplete="off"
                                @submit.prevent="save(item)"
                            >
                                <input
                                    v-model="secretDraft"
                                    type="password"
                                    autocomplete="new-password"
                                    class="ant-input min-w-0 flex-1"
                                    :aria-label="labelOf(item)"
                                    :placeholder="t('text.environment.secret_placeholder')"
                                    :disabled="busy !== ''"
                                    :data-testid="`env-secret-input-${item.key}`"
                                />
                                <a-button
                                    type="primary"
                                    html-type="submit"
                                    :loading="busy === item.key"
                                    :disabled="busy !== '' || !secretDraft"
                                    :data-testid="`env-save-${item.key}`"
                                >
                                    {{ t('text.environment.save') }}
                                </a-button>
                                <a-button
                                    :disabled="busy !== ''"
                                    :data-testid="`env-cancel-${item.key}`"
                                    @click="cancelEdit"
                                >
                                    {{ t('text.environment.cancel') }}
                                </a-button>
                            </form>
                            <div v-else class="flex flex-wrap items-center gap-2">
                                <a-button
                                    :disabled="busy !== ''"
                                    :data-testid="`env-replace-${item.key}`"
                                    @click="startEdit(item)"
                                >
                                    {{ t('text.environment.replace') }}
                                </a-button>
                            </div>
                        </template>

                        <!-- Non-secret: the current value is editable. -->
                        <form
                            v-else
                            class="flex flex-wrap items-center gap-2"
                            autocomplete="off"
                            @submit.prevent="save(item)"
                        >
                            <input
                                v-model="drafts[item.key]"
                                type="text"
                                autocomplete="off"
                                class="ant-input min-w-0 flex-1"
                                :aria-label="labelOf(item)"
                                :disabled="busy !== ''"
                                :data-testid="`env-input-${item.key}`"
                            />
                            <a-button
                                type="primary"
                                html-type="submit"
                                :loading="busy === item.key"
                                :disabled="busy !== '' || !canSaveDraft(item)"
                                :data-testid="`env-save-${item.key}`"
                            >
                                {{ t('text.environment.save') }}
                            </a-button>
                        </form>

                        <!-- Clear asks for an inline confirmation first. -->
                        <div v-if="item.has_value || item.value" class="flex flex-wrap items-center gap-2">
                            <template v-if="confirming === item.key">
                                <span class="text-sm" role="alert">{{ t('text.environment.clear_confirm', { name: labelOf(item) }) }}</span>
                                <a-button
                                    danger
                                    size="small"
                                    :loading="busy === item.key"
                                    :disabled="busy !== ''"
                                    :data-testid="`env-clear-yes-${item.key}`"
                                    @click="clear(item)"
                                >
                                    {{ t('text.environment.clear_yes') }}
                                </a-button>
                                <a-button
                                    size="small"
                                    :disabled="busy !== ''"
                                    :data-testid="`env-clear-no-${item.key}`"
                                    @click="confirming = ''"
                                >
                                    {{ t('text.environment.cancel') }}
                                </a-button>
                            </template>
                            <a-button
                                v-else
                                size="small"
                                danger
                                :disabled="busy !== ''"
                                :data-testid="`env-clear-${item.key}`"
                                @click="askClear(item)"
                            >
                                {{ t('text.environment.clear') }}
                            </a-button>
                        </div>

                        <a-alert
                            v-if="errors[item.key]"
                            type="error"
                            show-icon
                            :message="t('text.environment.save_error', { name: labelOf(item) })"
                            :description="t('text.environment.save_error_hint')"
                            :data-testid="`env-error-${item.key}`"
                        />
                    </div>
                </section>
            </template>
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { hasAccountData, isWebmaster } from '@/hooks/useCan'
import { clearEnvironment, fetchEnvironment, saveEnvironment } from '@/libs/environmentApi'

defineComponent({ name: 'SettingEnvironment' })

const { t, te } = useI18n()

/** Webmaster only: the API and the route guard refuse everyone else; this keeps the page body empty. */
const allowed = computed(() => isWebmaster())

/** The account is cached: before that the page shows nothing instead of a not-allowed flash. */
const accountReady = computed(() => hasAccountData())

/** Allowed settings as the API lists them (secret values are always null). */
const items = ref([])
const loading = ref(false)
const loadError = ref(false)
/** Key of the row being saved or cleared; empty when idle. One request at a time. */
const busy = ref('')
/** Key of the secret row whose replace input is open. */
const editing = ref('')
/** Key of the row waiting for the clear confirmation. */
const confirming = ref('')
/** Typed secret. It exists only while the replace input is open and is wiped on save, cancel and unmount. */
const secretDraft = ref('')
/** Editable values of non-secret rows, keyed by setting key. */
const drafts = reactive({})
/** Row key to "has an error" flag. */
const errors = reactive({})
const notice = ref('')
let disposed = false

/** Operator label of a row: its translated title, else the stored label, else the env name. */
const labelOf = (item) => (item.title && te(item.title) ? t(item.title) : item.label || item.env_name || item.key)

/** Rows grouped by setting group, in API order. */
const groups = computed(() => {
    const map = new Map()
    for (const item of items.value) {
        const name = item.group || ''
        if (!map.has(name)) {
            const gk = `text.s.${name}`
            map.set(name, { name, title: name && te(gk) ? t(gk) : t('text.environment.group_other'), items: [] })
        }
        map.get(name).items.push(item)
    }
    return [...map.values()]
})

/** Reset the typed secret. */
const wipeSecret = () => {
    secretDraft.value = ''
    editing.value = ''
}

/** Put a fresh item from the API into the list and refresh its draft. */
const applyItem = (fresh) => {
    if (!fresh || !fresh.key) return
    items.value = items.value.map((row) => (row.key === fresh.key ? fresh : row))
    if (!fresh.secret) drafts[fresh.key] = fresh.value ?? ''
}

/** Whether a non-secret draft differs from the saved value and is not empty. */
const canSaveDraft = (item) => {
    const draft = drafts[item.key]
    return typeof draft === 'string' && draft !== '' && draft !== (item.value ?? '')
}

/** Load the allowed settings. */
const load = async () => {
    if (loading.value) return
    loading.value = true
    loadError.value = false
    try {
        const body = (await fetchEnvironment())?.data || {}
        if (disposed) return
        items.value = Array.isArray(body.items) ? body.items : []
        for (const item of items.value) {
            if (!item.secret) drafts[item.key] = item.value ?? ''
        }
    } catch {
        if (!disposed) loadError.value = true
    } finally {
        if (!disposed) loading.value = false
    }
}

/** Open the replace input of a secret row (empty, never prefilled). */
const startEdit = (item) => {
    confirming.value = ''
    errors[item.key] = false
    secretDraft.value = ''
    editing.value = item.key
}

/** Close the replace input and drop what was typed. */
const cancelEdit = () => wipeSecret()

/** Ask for the inline confirmation before clearing. */
const askClear = (item) => {
    wipeSecret()
    errors[item.key] = false
    confirming.value = item.key
}

/**
 * Save a new value. The guard stops double submits; the busy flag is released in finally, and the typed secret is
 * wiped as soon as the request settles successfully.
 */
const save = async (item) => {
    if (busy.value) return
    const value = item.secret ? secretDraft.value : drafts[item.key]
    if (!value) return
    busy.value = item.key
    errors[item.key] = false
    try {
        const body = (await saveEnvironment(item.key, value))?.data
        if (disposed) return
        applyItem(body)
        if (item.secret) wipeSecret()
        notice.value = t('text.environment.saved', { name: labelOf(item) })
    } catch {
        if (!disposed) errors[item.key] = true
    } finally {
        if (!disposed) busy.value = ''
    }
}

/** Empty a value after the inline confirmation. */
const clear = async (item) => {
    if (busy.value) return
    busy.value = item.key
    errors[item.key] = false
    try {
        const body = (await clearEnvironment(item.key))?.data
        if (disposed) return
        applyItem(body)
        confirming.value = ''
        notice.value = t('text.environment.cleared', { name: labelOf(item) })
    } catch {
        if (!disposed) errors[item.key] = true
    } finally {
        if (!disposed) busy.value = ''
    }
}

onMounted(() => {
    if (allowed.value) load()
})

onBeforeUnmount(() => {
    disposed = true
    wipeSecret()
})
</script>
