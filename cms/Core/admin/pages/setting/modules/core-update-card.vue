<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <section class="i-box space-y-3" data-testid="core-update-card">
        <!--
            One line from sm up: "iSPA CMS <installed> · <newer or up to date> · <notes>". Below sm the parts stack and
            the dots are hidden, so a wrapped line never starts with a dot.
        -->
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex min-w-0 flex-col gap-y-1 sm:flex-row sm:flex-wrap sm:items-baseline sm:gap-x-2">
                <h3 class="m-0 text-base font-semibold" data-testid="core-update-title">
                    {{ $t('text.modules.core_update.title') }}
                    <span v-if="showVersion" data-testid="core-update-installed">{{ state.info.installed }}</span>
                </h3>
                <template v-if="showVersion && (newer || upToDate)">
                    <span aria-hidden="true" class="hidden text-sm text-ink-muted sm:inline" data-testid="core-update-dot">·</span>
                    <span v-if="newer" class="text-sm font-medium" data-testid="core-update-newer">
                        {{ $t('text.modules.core_update.newer', { version: state.info.latest }) }}
                    </span>
                    <span v-else class="text-sm text-ink-muted" data-testid="core-update-up-to-date">
                        {{ $t('text.modules.core_update.up_to_date') }}
                    </span>
                </template>
                <template v-if="showVersion && state.info.notes_url">
                    <span aria-hidden="true" class="hidden text-sm text-ink-muted sm:inline" data-testid="core-update-dot">·</span>
                    <a
                        :href="state.info.notes_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm"
                        data-testid="core-update-notes"
                    >
                        {{ $t('text.modules.core_update.notes') }}
                    </a>
                </template>
            </div>
            <i-button
                v-if="state.loaded && !running && !managed"
                variant="primary"
                size="sm"
                data-testid="core-update-open"
                :disabled="!state.info.can_update"
                @click="openConfirm"
            >
                {{ $t('text.modules.core_update.action.update') }}
            </i-button>
        </div>

        <div v-if="state.loading && !state.loaded" aria-busy="true" data-testid="core-update-loading">
            <a-skeleton active :paragraph="{ rows: 1 }" />
        </div>
        <div v-else-if="state.error" role="alert" class="space-y-2" data-testid="core-update-error">
            <p class="m-0 text-sm text-danger">{{ state.error }}</p>
            <i-button size="sm" data-testid="core-update-retry" @click="load()">
                {{ $t('text.modules.install.retry') }}
            </i-button>
        </div>
        <!-- A site iSPA deploys itself (no package manifest): no version, button, command or issue list -->
        <p v-else-if="managed" class="m-0 text-sm" data-testid="core-update-managed">
            {{ $t('text.modules.core_update.managed') }}
        </p>
        <template v-else-if="state.loaded">
            <!-- Outcome of the last update or restore followed on this page; it stays until the next action -->
            <a-alert
                v-if="result.text && !running"
                :type="result.ok ? 'success' : 'error'"
                show-icon
                :message="result.text"
                data-testid="core-update-result"
            />

            <p v-if="waiting" class="m-0 text-sm" role="status" data-testid="core-update-waiting">
                {{ $t('text.modules.core_update.waiting') }}
            </p>
            <div v-else-if="running" class="space-y-1" data-testid="core-update-running">
                <p class="m-0 text-sm">{{ $t('text.modules.core_update.running') }}</p>
                <a-progress :percent="running.progress" status="active" size="small" data-testid="core-update-progress" />
            </div>

            <div v-if="blocked" class="space-y-2" data-testid="core-update-blocked">
                <p class="m-0 text-sm">{{ $t('text.modules.core_update.blocked') }}</p>
                <ul class="m-0 list-disc space-y-1 pl-5 text-sm">
                    <li v-for="issue in issueRows" :key="issue.code" data-testid="core-update-issue">
                        {{ issue.text }}
                        <span v-if="issue.files.length" class="block break-all text-xs text-ink-muted">
                            {{ issue.files.join(', ') }}
                        </span>
                        <!-- Code owned by root: the runner never runs as root, so there is no line to forward -->
                        <p
                            v-if="issue.code === 'CORE_NO_RUNNER' && rootOwner"
                            class="m-0 mt-2"
                            data-testid="core-update-root-owner"
                        >
                            {{ $t('text.modules.core_update.runner_setup_root') }}
                        </p>
                        <!-- No runner: the one line the server manager adds, ready to forward -->
                        <div
                            v-else-if="issue.code === 'CORE_NO_RUNNER' && setupLine"
                            class="mt-2 space-y-2"
                            data-testid="core-update-runner-setup"
                        >
                            <p class="m-0">
                                {{ $t('text.modules.core_update.runner_setup', { user: state.info.runner_setup?.user || '' }) }}
                            </p>
                            <code
                                :ref="(el) => (setupCode = el)"
                                class="block break-all rounded-md bg-primary-soft px-2 py-1 text-xs text-ink"
                                data-testid="core-update-setup-line"
                            >{{ setupLine }}</code>
                            <i-button size="sm" data-testid="core-update-copy" @click="copyLine">
                                <span aria-live="polite">
                                    {{ $t(copied.line ? 'text.modules.core_update.action.copied' : 'text.modules.core_update.action.copy') }}
                                </span>
                            </i-button>
                        </div>
                    </li>
                </ul>
                <!--
                    Simple hosting: give the site folder to the web server user once and updates run on their own (no
                    cron). One command, ready to copy, then "Check again".
                -->
                <div v-if="ownerCommand" class="space-y-2 text-sm" data-testid="core-update-owner-fix">
                    <p class="m-0">{{ $t('text.modules.core_update.fix_owner', { user: state.info.web_user }) }}</p>
                    <code
                        :ref="(el) => (ownerCode = el)"
                        class="block break-all rounded-md bg-primary-soft px-2 py-1 text-xs text-ink"
                        data-testid="core-update-owner-command"
                    >{{ ownerCommand }}</code>
                    <i-button size="sm" data-testid="core-update-copy-owner" @click="copyOwner">
                        <span aria-live="polite">
                            {{ $t(copied.owner ? 'text.modules.core_update.action.copied' : 'text.modules.core_update.action.copy') }}
                        </span>
                    </i-button>
                </div>
                <!-- Ready-made instructions for an AI coding agent; collapsed so the person reads them before copying -->
                <details
                    v-if="showAgentPrompt"
                    class="rounded-md border border-border px-2 py-1 text-sm"
                    data-testid="core-update-agent-prompt"
                >
                    <summary class="cursor-pointer font-medium">{{ $t('text.modules.core_update.agent_prompt.title') }}</summary>
                    <div class="mt-2 space-y-2">
                        <p class="m-0 text-xs text-ink-muted">{{ $t('text.modules.core_update.agent_prompt.help') }}</p>
                        <pre
                            :ref="(el) => (promptPre = el)"
                            class="m-0 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-md bg-primary-soft px-2 py-1 font-mono text-xs text-ink [overflow-wrap:anywhere]"
                            data-testid="core-update-prompt-text"
                        >{{ agentPrompt }}</pre>
                        <i-button size="sm" data-testid="core-update-copy-prompt" @click="copyPrompt">
                            <span aria-live="polite">
                                {{ $t(copied.prompt ? 'text.modules.core_update.action.copied' : 'text.modules.core_update.agent_prompt.copy') }}
                            </span>
                        </i-button>
                    </div>
                </details>
                <div class="flex flex-wrap gap-2">
                    <i-button v-if="state.info.can_restore" size="sm" data-testid="core-update-restore-open" @click="openRestore">
                        {{ $t('text.modules.core_update.action.restore') }}
                    </i-button>
                    <!-- After a fix on the server: read the card again instead of reloading the page -->
                    <i-button size="sm" data-testid="core-update-recheck" :loading="state.loading" :disabled="state.loading" @click="recheck">
                        {{ $t('text.modules.core_update.action.recheck') }}
                    </i-button>
                </div>
                <p v-if="showCommandHint" class="m-0 text-sm">
                    {{ $t('text.modules.core_update.command_hint') }}
                    <code class="break-all" data-testid="core-update-command">{{ state.info.command }}</code>
                </p>
            </div>
        </template>

        <!-- Confirmation: an upgrade only starts after the person confirms there is a backup -->
        <a-drawer
            :open="confirm.open"
            width="min(480px, 100vw)"
            :title="$t('text.modules.core_update.confirm.title', { version: state.info.latest })"
            @close="closeConfirm"
        >
            <form class="space-y-4" data-testid="core-update-form" novalidate @submit.prevent="submit">
                <a-alert
                    v-if="confirm.error"
                    type="error"
                    show-icon
                    :message="confirm.error"
                    data-testid="core-update-submit-error"
                />
                <p class="m-0 text-sm">{{ $t('text.modules.core_update.confirm.text') }}</p>
                <label class="flex cursor-pointer items-start gap-2">
                    <input v-model="confirm.backup" type="checkbox" class="mt-1" data-testid="core-update-backup" />
                    <span class="text-sm font-medium">{{ $t('text.modules.core_update.confirm.backup') }}</span>
                </label>
                <div class="flex flex-wrap justify-end gap-2">
                    <i-button size="sm" data-testid="core-update-cancel" @click="closeConfirm">
                        {{ $t('text.modules.install.action.cancel') }}
                    </i-button>
                    <i-button
                        variant="primary"
                        size="sm"
                        native-type="submit"
                        data-testid="core-update-start"
                        :loading="confirm.loading"
                        :disabled="!confirm.backup || confirm.loading"
                    >
                        {{ $t('text.modules.core_update.action.update') }}
                    </i-button>
                </div>
            </form>
        </a-drawer>

        <!-- Restore: the old files are only put back after the person confirms -->
        <a-drawer
            :open="restore.open"
            width="min(480px, 100vw)"
            :title="$t('text.modules.core_update.restore_confirm.title')"
            @close="closeRestore"
        >
            <form class="space-y-4" data-testid="core-update-restore-form" novalidate @submit.prevent="submitRestore">
                <a-alert
                    v-if="restore.error"
                    type="error"
                    show-icon
                    :message="restore.error"
                    data-testid="core-update-restore-error"
                />
                <p class="m-0 text-sm">{{ $t('text.modules.core_update.restore_confirm.text') }}</p>
                <label class="flex cursor-pointer items-start gap-2">
                    <input v-model="restore.checked" type="checkbox" class="mt-1" data-testid="core-update-restore-check" />
                    <span class="text-sm font-medium">{{ $t('text.modules.core_update.restore_confirm.check') }}</span>
                </label>
                <div class="flex flex-wrap justify-end gap-2">
                    <i-button size="sm" data-testid="core-update-restore-cancel" @click="closeRestore">
                        {{ $t('text.modules.install.action.cancel') }}
                    </i-button>
                    <i-button
                        variant="primary"
                        size="sm"
                        native-type="submit"
                        data-testid="core-update-restore-start"
                        :loading="restore.loading"
                        :disabled="!restore.checked || restore.loading"
                    >
                        {{ $t('text.modules.core_update.action.restore') }}
                    </i-button>
                </div>
            </form>
        </a-drawer>
    </section>
</template>

<script setup>
import { computed, defineComponent, onBeforeUnmount, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'

defineComponent({
    name: 'CoreUpdateCard',
})

const emit = defineEmits(['finished'])

/** Milliseconds between refreshes while an upgrade is queued or running. */
const POLL_MS = 5000
const ENDPOINT = 'core-update'
const TASK_ENDPOINT = 'background-tasks'
/** Milliseconds the copy button says "copied". */
const COPIED_MS = 2000
/** Preflight codes that have their own plain text; anything else falls back to the generic text. */
const ISSUE_CODES = [
    'CORE_MODIFIED',
    'CORE_CUSTOM_DEPS',
    'CORE_CUSTOM_DEPS_CONFLICT',
    'CORE_NOT_WRITABLE',
    'CORE_DISK',
    'CORE_PHP',
    'CORE_UPGRADE_INTERRUPTED',
    'CORE_NO_QUEUE',
    'CORE_NO_MANIFEST',
    'CORE_NO_RUNNER',
    'CORE_RELEASE_LAYOUT',
    'CORE_MODULE_INSTALL_RUNNING',
    'CORE_CODE_DIR_SHARED',
]
/** Problems the card itself answers (restore button), so they alone never bring the command hint. */
const SELF_SERVICE_CODES = ['CORE_UPGRADE_INTERRUPTED']
/**
 * Problems that hide the command hint whatever else is listed: with no runner the setup block and the AI agent prompt
 * cover the other blockers, a running module install is transient, and a shared code folder makes the command refuse
 * too (the prompt covers it).
 */
const HIDE_HINT_CODES = ['CORE_NO_RUNNER', 'CORE_MODULE_INSTALL_RUNNING', 'CORE_CODE_DIR_SHARED']
/** Failure codes of a finished task that have their own plain text; anything else gets the generic text. */
const RESULT_KEYS = {
    CORE_RUNNER_TIMEOUT: 'text.modules.core_update.result.CORE_RUNNER_TIMEOUT',
    CORE_RUNNER_DIED: 'text.modules.core_update.result.CORE_RUNNER_DIED',
}
const quiet = { silent: true }

const { t } = useI18n()
const state = reactive({
    loading: false,
    loaded: false,
    error: '',
    info: { installed: '', latest: null, managed: false, web_user: null, notes_url: null, can_update: false, preflight: { ok: true, issues: [] }, running: null, command: './ispa upgrade', runner: null, runner_setup: { user: '', line: '', base: '', root_owner: false }, can_restore: false },
})
const confirm = reactive({ open: false, backup: false, loading: false, error: '' })
const restore = reactive({ open: false, checked: false, loading: false, error: '' })
/** Outcome of the last task followed on this page. */
const result = reactive({ ok: false, text: '' })
/** Which copy button says "copied". */
const copied = reactive({ line: false, prompt: false, owner: false })

let timer = null
let disposed = false
let hadRunning = false
/** The task being followed: its uuid, and what this page asked for ('upgrade' | 'restore' | '' when unknown). */
let tracked = { uuid: '', kind: '' }
let setupCode = null
let promptPre = null
let ownerCode = null
const copiedTimers = { line: null, prompt: null, owner: null }

const running = computed(() => {
    const task = state.info.running
    if (!task) return null
    return { ...task, progress: Math.max(0, Math.min(100, Number(task.progress) || 0)) }
})
const issues = computed(() => (Array.isArray(state.info.preflight?.issues) ? state.info.preflight.issues : []))
const waiting = computed(() => running.value?.status === 'queued')
const setupLine = computed(() => String(state.info.runner_setup?.line || ''))
const showCommandHint = computed(
    () =>
        !issues.value.some((issue) => HIDE_HINT_CODES.includes(issue.code)) &&
        issues.value.some((issue) => !SELF_SERVICE_CODES.includes(issue.code)),
)
const rootOwner = computed(() => state.info.runner_setup?.root_owner === true)
const hasIssue = (code) => issues.value.some((issue) => issue.code === code)
/** The crontab part of the prompt: no runner, a line to add, and a code owner that is not root. */
const needsRunner = computed(() => hasIssue('CORE_NO_RUNNER') && !!setupLine.value && !rootOwner.value)
/** A shell word: kept as is when plain, else single-quoted. */
const shellWord = (value) => (/^[A-Za-z0-9_@%+=:,./-]+$/.test(value) ? value : `'${value.replace(/'/g, `'\\''`)}'`)
/**
 * The one-line fix for simple hosting: the site folder goes to the web server user, which then runs the update
 * itself. Offered when the code belongs to root, to an account with no runner, or is shared while no runner works;
 * never when a runner of a separate code owner is set up (that setup is kept on purpose).
 */
const ownerCommand = computed(() => {
    const web = String(state.info.web_user || '')
    const base = String(state.info.runner_setup?.base || '')
    if (!web || !base || state.info.runner) return ''
    if (!rootOwner.value && !hasIssue('CORE_NO_RUNNER') && !hasIssue('CORE_CODE_DIR_SHARED')) return ''
    if (!rootOwner.value && state.info.runner_setup?.user === web && !hasIssue('CORE_CODE_DIR_SHARED')) return ''
    return `sudo chown -R ${shellWord(web)}:${shellWord(web)} ${shellWord(base)}`
})
const showAgentPrompt = computed(() => needsRunner.value || hasIssue('CORE_CODE_DIR_SHARED'))
/**
 * Instructions the server manager pastes into an AI coding agent: who and where, then a section per problem it can
 * help with (the runner line, the folder permissions, the changed core files, all of them one per line), then the
 * limits and what to report.
 */
const agentPrompt = computed(() => {
    const setup = state.info.runner_setup || {}
    const base = String(setup.base || '')
    const user = String(setup.user || '')
    const parts = [t('text.modules.core_update.agent_prompt.intro', { base, user })]
    if (needsRunner.value) {
        parts.push(t('text.modules.core_update.agent_prompt.body', { base, user, line: setupLine.value }))
    }
    if (hasIssue('CORE_CODE_DIR_SHARED')) {
        parts.push(t('text.modules.core_update.agent_prompt.code_dir_shared', { base }))
    }
    const modified = issues.value.find((issue) => issue.code === 'CORE_MODIFIED')
    if (modified) {
        const files = Array.isArray(modified.files) ? modified.files.map(String) : []
        parts.push(t('text.modules.core_update.agent_prompt.modified', { base, files: files.join('\n') }))
    }
    parts.push(t('text.modules.core_update.agent_prompt.limits'))
    return parts.join('\n\n')
})
const blocked = computed(() => !state.info.can_update && !running.value && issues.value.length > 0)
const managed = computed(() => state.loaded && state.info.managed === true)
const showVersion = computed(() => state.loaded && !managed.value && !!state.info.installed)
/** The hub offers a version above the installed one. */
const newer = computed(() => !!state.info.latest && compareVersions(state.info.latest, state.info.installed) > 0)
/** The hub knows the newest version and this site runs it; when the hub does not know, only the version shows. */
const upToDate = computed(() => !!state.info.latest && !newer.value)
const issueRows = computed(() =>
    issues.value.map((issue) => ({
        code: issue.code,
        text: t(ISSUE_CODES.includes(issue.code) ? `text.modules.core_update.issue.${issue.code}` : 'text.modules.core_update.issue.unknown'),
        files: Array.isArray(issue.files) ? issue.files.slice(0, 5) : [],
    })),
)

/**
 * Compare dotted numeric versions ("2026.12.1" > "2026.12.0"); a missing part counts as 0.
 */
function compareVersions(a, b) {
    const left = String(a || '').split('.')
    const right = String(b || '').split('.')
    for (let i = 0; i < Math.max(left.length, right.length); i++) {
        const diff = (parseInt(left[i], 10) || 0) - (parseInt(right[i], 10) || 0)
        if (diff !== 0) return diff > 0 ? 1 : -1
    }
    return 0
}

const errorCodeOf = (error) => {
    const body = error?.response?.data || {}
    return body.errorCode || body.error_code || null
}

/**
 * Poll while an upgrade is queued or running; when it ends the card and the module list are refreshed.
 */
const schedule = () => {
    clearTimeout(timer)
    timer = null
    if (disposed) return
    if (state.info.running) {
        hadRunning = true
        if (state.info.running.uuid) tracked.uuid = state.info.running.uuid
        timer = setTimeout(() => load(true), POLL_MS)
    } else if (hadRunning) {
        hadRunning = false
        showOutcome()
        emit('finished')
    }
}

const clearResult = () => {
    result.ok = false
    result.text = ''
}

/**
 * Read once how the followed task ended. Only plain text chosen here is shown: the task's own message can hold
 * server details, so it never reaches the screen. A task started by another person answers 404 and shows nothing.
 */
const showOutcome = async () => {
    const { uuid, kind } = tracked
    tracked = { uuid: '', kind: '' }
    if (!uuid) return
    try {
        const res = await ajax.get(`${TASK_ENDPOINT}/${uuid}`, quiet)
        const task = res?.data?.data
        if (disposed || !task || state.info.running) return
        if (task.status === 'succeeded') {
            result.ok = true
            result.text =
                task.result?.restored || kind === 'restore'
                    ? t('text.modules.core_update.result.restored')
                    : t('text.modules.core_update.result.updated', { version: task.result?.to || state.info.installed })
        } else if (task.status === 'failed' && task.message_params?.code === 'CORE_MODULE_NEEDS_UPDATE') {
            // Modules updated on their own would not accept the new version: name them so they are updated first.
            result.ok = false
            result.text = t('text.modules.core_update.needs_module_update', { modules: String(task.message_params.modules || '') })
        } else if (task.status === 'failed') {
            result.ok = false
            result.text = t(
                RESULT_KEYS[task.message_params?.code] ||
                    (kind === 'restore' ? 'text.modules.core_update.result.restore_failed' : 'text.modules.core_update.result.failed'),
            )
        }
    } catch {
        // No outcome to show; the card already reflects the current state.
    }
}

/**
 * Show the queued state at once and start following the new task.
 */
const follow = (res, kind) => {
    tracked = { uuid: String(res?.data?.data?.background_task_uuid || ''), kind }
    state.info = { ...state.info, can_update: false, can_restore: false, running: { uuid: tracked.uuid, progress: 0, status: 'queued' } }
    schedule()
    load(true)
}

/**
 * Load the card. A silent refresh (the poll) keeps the last data when it fails.
 */
const load = async (silent = false) => {
    if (!silent) {
        state.loading = true
        state.error = ''
    }
    try {
        const res = await ajax.get(ENDPOINT, quiet)
        const data = res?.data?.data
        if (data && typeof data === 'object') {
            state.info = { ...state.info, ...data }
            state.loaded = true
        }
    } catch {
        if (!silent) state.error = t('text.modules.core_update.load_failed')
    } finally {
        state.loading = false
        schedule()
    }
}

const openConfirm = () => {
    if (!state.info.can_update) return
    clearResult()
    confirm.backup = false
    confirm.error = ''
    confirm.open = true
}

const closeConfirm = () => {
    if (confirm.loading) return
    confirm.open = false
}

/**
 * Queue the upgrade. The button stays busy while the request runs and is released only on an error.
 */
const submit = async () => {
    if (confirm.loading) return
    if (!confirm.backup || !state.info.latest) return
    confirm.loading = true
    confirm.error = ''
    try {
        const res = await ajax.post(ENDPOINT, { version: state.info.latest, confirm_backup: true }, quiet)
        confirm.open = false
        confirm.loading = false
        follow(res, 'upgrade')
    } catch (error) {
        confirm.loading = false
        const code = errorCodeOf(error)
        if (code === 'CORE_UPGRADE_RUNNING') {
            confirm.open = false
            load(true)
            return
        }
        if (code === 'CORE_PREFLIGHT_FAILED' || code === 'CORE_NO_MANIFEST') {
            const preflight = error?.response?.data?.data?.preflight
            if (preflight) state.info = { ...state.info, can_update: false, preflight }
            confirm.open = false
            return
        }
        confirm.error = t('text.modules.core_update.error.generic')
    }
}

const openRestore = () => {
    if (!state.info.can_restore) return
    clearResult()
    restore.checked = false
    restore.error = ''
    restore.open = true
}

const closeRestore = () => {
    if (restore.loading) return
    restore.open = false
}

/**
 * Ask for the old files back. The button stays busy while the request runs and is released only on an error.
 */
const submitRestore = async () => {
    if (restore.loading) return
    if (!restore.checked) return
    restore.loading = true
    restore.error = ''
    try {
        const res = await ajax.post(`${ENDPOINT}/restore`, { confirm: true }, quiet)
        restore.open = false
        restore.loading = false
        follow(res, 'restore')
    } catch (error) {
        restore.loading = false
        // The state changed under the drawer (already running, nothing left to restore, runner gone): show it.
        if (['CORE_UPGRADE_RUNNING', 'CORE_NOT_INTERRUPTED', 'CORE_NO_RUNNER'].includes(errorCodeOf(error))) {
            restore.open = false
            load(true)
            return
        }
        restore.error = t('text.modules.core_update.restore_confirm.error')
    }
}

/**
 * Copy text to the clipboard. Without the clipboard API (admin on plain HTTP) the element's text is selected and
 * copied the old way; returns whether the copy really happened.
 */
const copyText = async (text, el) => {
    let done = false
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text)
            done = true
        }
    } catch {
        done = false
    }
    if (!done && el) {
        const range = document.createRange()
        range.selectNodeContents(el)
        const selection = window.getSelection()
        selection?.removeAllRanges()
        selection?.addRange(range)
        try {
            done = document.execCommand('copy')
        } catch {
            done = false
        }
    }
    return done
}

/** "Copied" shows on that button for a moment, and only when the copy really happened. */
const markCopied = (which, done) => {
    if (!done) return
    copied[which] = true
    clearTimeout(copiedTimers[which])
    copiedTimers[which] = setTimeout(() => (copied[which] = false), COPIED_MS)
}

const copyLine = async () => markCopied('line', await copyText(setupLine.value, setupCode))

const copyOwner = async () => markCopied('owner', await copyText(ownerCommand.value, ownerCode))

/** Read the card again after a fix on the server; the button is busy until the answer arrives. */
const recheck = () => {
    if (state.loading) return
    clearResult()
    load()
}

const copyPrompt = async () => markCopied('prompt', await copyText(agentPrompt.value, promptPre))

onMounted(() => load())

onBeforeUnmount(() => {
    disposed = true
    clearTimeout(timer)
    clearTimeout(copiedTimers.line)
    clearTimeout(copiedTimers.prompt)
    clearTimeout(copiedTimers.owner)
})
</script>
