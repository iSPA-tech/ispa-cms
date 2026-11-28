<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <!--
        The look follows the state (data-state): a new version and a running update are tinted with the accent, a
        finished one is green, a failed one red, a refused one amber; up to date and "kept by iSPA" stay neutral.
        Every state also has its own icon and a short text badge, so colour is never the only sign.
    -->
    <section
        class="overflow-hidden rounded-xl border bg-surface shadow-xs"
        :class="look.border"
        :data-state="tone"
        data-testid="core-update-card"
    >
        <!-- Button under the text below sm, beside it from sm up -->
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" :class="look.band">
            <div class="flex min-w-0 items-start gap-3">
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-full text-lg"
                    :class="look.icon"
                    aria-hidden="true"
                    data-testid="core-update-icon"
                >
                    <component :is="look.glyph" :spin="tone === 'progress'" />
                </span>
                <div class="min-w-0 space-y-1.5">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h3 class="m-0 text-base font-semibold" data-testid="core-update-title">
                            {{ $t('text.modules.core_update.title') }}
                        </h3>
                        <span
                            v-if="badge"
                            class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="look.badge"
                            :data-testid="badge.testid"
                        >
                            {{ badge.text }}
                        </span>
                    </div>
                    <!-- A site iSPA deploys itself (no package manifest): no version, button, command or issue list -->
                    <p v-if="managed" class="m-0 text-sm" data-testid="core-update-managed">
                        {{ $t('text.modules.core_update.managed') }}
                    </p>
                    <!-- Installed version, and the new one beside it when the hub offers one -->
                    <dl v-if="showVersion" class="m-0 flex flex-wrap items-end gap-x-4 gap-y-1" data-testid="core-update-versions">
                        <div class="min-w-0">
                            <dt class="text-xs font-normal text-ink-muted">{{ $t('text.modules.core_update.installed') }}</dt>
                            <dd class="m-0 break-all text-xl font-semibold leading-tight sm:text-2xl" data-testid="core-update-installed">
                                {{ state.info.installed }}
                            </dd>
                        </div>
                        <!-- The arrow only from sm up: on a phone each version keeps the full line -->
                        <ArrowRightOutlined v-if="newer" class="hidden pb-1.5 text-ink-muted sm:inline-block" aria-hidden="true" />
                        <div v-if="newer" class="min-w-0">
                            <dt class="text-xs font-normal text-ink-muted">{{ $t('text.modules.core_update.latest') }}</dt>
                            <dd
                                class="m-0 break-all text-xl font-semibold leading-tight text-primary-ink sm:text-2xl"
                                data-testid="core-update-latest"
                            >
                                {{ state.info.latest }}
                            </dd>
                        </div>
                    </dl>
                    <a
                        v-if="showVersion && state.info.notes_url"
                        :href="state.info.notes_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-block text-sm font-medium underline"
                        data-testid="core-update-notes"
                    >
                        {{ $t('text.modules.core_update.notes') }}
                    </a>
                </div>
            </div>
            <i-button
                v-if="state.loaded && !running && !managed"
                variant="primary"
                :size="state.info.can_update ? 'md' : 'sm'"
                class="shrink-0 self-start sm:self-center"
                data-testid="core-update-open"
                :disabled="!state.info.can_update"
                @click="openConfirm"
            >
                {{ $t('text.modules.core_update.action.update') }}
            </i-button>
        </div>

        <div v-if="hasBody" class="space-y-3 border-t border-border p-4" data-testid="core-update-body">
            <div v-if="state.loading && !state.loaded" aria-busy="true" data-testid="core-update-loading">
                <a-skeleton active :paragraph="{ rows: 1 }" />
            </div>
            <div v-else-if="state.error" role="alert" class="space-y-2" data-testid="core-update-error">
                <p class="m-0 text-sm font-medium text-danger-ink">{{ state.error }}</p>
                <i-button size="sm" data-testid="core-update-retry" @click="load()">
                    {{ $t('text.modules.install.retry') }}
                </i-button>
            </div>
            <template v-else>
                <!-- Outcome of the last update or restore followed on this page; it stays until the next action -->
                <a-alert
                    v-if="result.text && !running"
                    :type="result.ok ? 'success' : 'error'"
                    show-icon
                    :message="result.text"
                    data-testid="core-update-result"
                />

                <!--
                    After an update this page watched succeed: the admin reloads itself to run the new build. The
                    sentence is said once to a screen reader (hidden line, fixed number); the visible one counts down.
                -->
                <div v-if="reloadPrompt.mode && !running" class="space-y-2" data-testid="core-update-reload">
                    <template v-if="reloadPrompt.mode === 'countdown'">
                        <p class="sr-only" role="status" aria-live="polite" data-testid="core-update-reload-live">
                            {{ $t('text.modules.core_update.reload_countdown', { seconds: RELOAD_SECONDS }) }}
                        </p>
                        <p class="m-0 text-base font-semibold" aria-hidden="true" data-testid="core-update-reload-text">
                            {{ $t('text.modules.core_update.reload_countdown', { seconds: reloadPrompt.seconds }) }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <i-button
                                variant="primary"
                                size="md"
                                data-testid="core-update-reload-now"
                                :loading="reloadPrompt.busy"
                                :disabled="reloadPrompt.busy"
                                @click="reloadNow"
                            >
                                {{ $t('text.modules.core_update.action.reload_now') }}
                            </i-button>
                            <i-button size="md" data-testid="core-update-reload-later" :disabled="reloadPrompt.busy" @click="reloadLater">
                                {{ $t('text.modules.core_update.action.reload_later') }}
                            </i-button>
                        </div>
                    </template>
                    <template v-else>
                        <p class="m-0 text-sm" data-testid="core-update-reload-hint">
                            {{ $t('text.modules.core_update.reload_hint') }}
                        </p>
                        <i-button
                            size="md"
                            data-testid="core-update-reload-page"
                            :loading="reloadPrompt.busy"
                            :disabled="reloadPrompt.busy"
                            @click="reloadNow"
                        >
                            {{ $t('text.modules.core_update.action.reload_page') }}
                        </i-button>
                    </template>
                </div>

                <!--
                    Queued or running: one live line (the same element for both, so a reader hears the change), a bar,
                    and the note that nobody has to reload. A queued request has no percentage yet: its bar only pulses.
                -->
                <div v-if="running" class="space-y-2" data-testid="core-update-activity">
                    <p
                        class="m-0 text-base font-semibold"
                        role="status"
                        aria-live="polite"
                        :data-testid="waiting ? 'core-update-waiting' : 'core-update-running'"
                    >
                        {{ $t(waiting ? 'text.modules.core_update.waiting' : 'text.modules.core_update.running') }}
                    </p>
                    <div
                        v-if="waiting"
                        class="h-2 overflow-hidden rounded-full bg-primary-soft"
                        aria-hidden="true"
                        data-testid="core-update-waiting-bar"
                    >
                        <div class="h-2 w-1/3 animate-pulse rounded-full bg-primary motion-reduce:animate-none" />
                    </div>
                    <a-progress v-else :percent="running.progress" status="active" data-testid="core-update-progress" />
                    <p class="m-0 text-sm text-ink-muted" data-testid="core-update-auto-refresh">
                        {{ $t('text.modules.core_update.auto_refresh') }}
                    </p>
                </div>

                <div v-if="blocked" class="space-y-2" data-testid="core-update-blocked">
                    <p class="m-0 text-sm font-semibold">{{ $t('text.modules.core_update.blocked') }}</p>
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
                        Changed core files (often a hot patch the new version already contains) are the only problem: the
                        Webmaster may update and overwrite them after confirming in the drawer.
                    -->
                    <div v-if="state.info.can_overwrite" class="space-y-2 text-sm" data-testid="core-update-overwrite">
                        <p class="m-0">{{ $t('text.modules.core_update.overwrite.help') }}</p>
                        <i-button variant="primary" size="sm" data-testid="core-update-overwrite-open" @click="openConfirm(true)">
                            {{ $t('text.modules.core_update.action.update_overwrite') }}
                        </i-button>
                    </div>
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
                    <div v-if="showCommandHint" class="space-y-2 text-sm" data-testid="core-update-command-hint">
                        <p class="m-0">
                            {{ $t('text.modules.core_update.command_hint') }}
                            <code :ref="(el) => (commandCode = el)" class="break-all" data-testid="core-update-command">{{ state.info.command }}</code>
                        </p>
                        <i-button size="sm" data-testid="core-update-copy-command" @click="copyCommand">
                            <span aria-live="polite">
                                {{ $t(copied.command ? 'text.modules.core_update.action.copied' : 'text.modules.core_update.action.copy_command') }}
                            </span>
                        </i-button>
                    </div>
                </div>
            </template>
        </div>

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
                <div v-if="confirm.overwrite" class="space-y-2" data-testid="core-update-overwrite-files">
                    <p class="m-0 text-sm">{{ $t('text.modules.core_update.confirm.overwrite_text') }}</p>
                    <ul class="m-0 list-disc space-y-1 pl-5 text-xs">
                        <li v-for="file in modifiedFiles" :key="file" class="break-all">{{ file }}</li>
                    </ul>
                </div>
                <label class="flex cursor-pointer items-start gap-2">
                    <input v-model="confirm.backup" type="checkbox" class="mt-1" data-testid="core-update-backup" />
                    <span class="text-sm font-medium">{{ $t('text.modules.core_update.confirm.backup') }}</span>
                </label>
                <label v-if="confirm.overwrite" class="flex cursor-pointer items-start gap-2">
                    <input v-model="confirm.overwriteChecked" type="checkbox" class="mt-1" data-testid="core-update-overwrite-check" />
                    <span class="text-sm font-medium">{{ $t('text.modules.core_update.confirm.overwrite') }}</span>
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
                        :disabled="!canSubmit"
                    >
                        {{ $t(confirm.overwrite ? 'text.modules.core_update.action.update_overwrite' : 'text.modules.core_update.action.update') }}
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
import {
    ArrowRightOutlined,
    ArrowUpOutlined,
    CheckCircleFilled,
    CheckOutlined,
    CloseCircleFilled,
    ExclamationCircleFilled,
    InfoCircleOutlined,
    LockOutlined,
    SyncOutlined,
} from '@ant-design/icons-vue'
import ajax from '@/ajax'
import { reloadAdminBuild } from './admin-reload'

defineComponent({
    name: 'CoreUpdateCard',
})

const emit = defineEmits(['finished'])

/** Milliseconds between refreshes while an upgrade is queued or running. */
const POLL_MS = 5000
const ENDPOINT = 'core-update'
const TASK_ENDPOINT = 'background-tasks'
/** Seconds before the admin reloads itself after an update this page watched succeed. */
const RELOAD_SECONDS = 5
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
    info: { installed: '', latest: null, managed: false, web_user: null, notes_url: null, can_update: false, can_overwrite: false, preflight: { ok: true, issues: [] }, running: null, command: './ispa upgrade', runner: null, runner_setup: { user: '', line: '', base: '', root_owner: false }, can_restore: false },
})
/** The update drawer; `overwrite` when it updates over changed core files (needs its own tick). */
const confirm = reactive({ open: false, backup: false, overwrite: false, overwriteChecked: false, loading: false, error: '' })
const restore = reactive({ open: false, checked: false, loading: false, error: '' })
/** Outcome of the last task followed on this page. */
const result = reactive({ ok: false, text: '' })
/** The reload offer after an update: '' (none), 'countdown' or 'later' (countdown stopped, plain button left). */
const reloadPrompt = reactive({ mode: '', seconds: RELOAD_SECONDS, busy: false })
/** Which copy button says "copied". */
const copied = reactive({ line: false, prompt: false, owner: false, command: false })

let timer = null
let reloadTimer = null
let disposed = false
let hadRunning = false
/** The task being followed: its uuid, and what this page asked for ('upgrade' | 'restore' | '' when unknown). */
let tracked = { uuid: '', kind: '' }
let setupCode = null
let promptPre = null
let ownerCode = null
let commandCode = null
const copiedTimers = { line: null, prompt: null, owner: null, command: null }

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
/** The core files changed on this site (the CORE_MODIFIED issue), listed in the overwrite drawer. */
const modifiedFiles = computed(() => {
    const files = issues.value.find((issue) => issue.code === 'CORE_MODIFIED')?.files
    return Array.isArray(files) ? files.map(String) : []
})
const canSubmit = computed(() => confirm.backup && (!confirm.overwrite || confirm.overwriteChecked) && !confirm.loading)
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
/**
 * The one state the card shows, most urgent first: a status that could not be read, a site iSPA keeps, a queued or
 * running task, how the last task ended, a refused update, a new version, and otherwise calm.
 * A refusal only raises the card when there is something to do: a new version, or old files waiting to be restored.
 */
const tone = computed(() => {
    if (state.error) return 'danger'
    if (!state.loaded || managed.value) return 'neutral'
    if (running.value) return 'progress'
    if (result.text) return result.ok ? 'success' : 'danger'
    if (blocked.value && (newer.value || hasIssue('CORE_UPGRADE_INTERRUPTED'))) return 'warning'
    if (newer.value) return 'available'
    return 'neutral'
})
/** Classes and icon per state; only colour tokens the admin already has. */
const LOOKS = {
    available: { border: 'border-primary', band: 'bg-primary-soft', icon: 'bg-primary text-white', badge: 'bg-primary text-white', glyph: ArrowUpOutlined },
    progress: { border: 'border-primary', band: 'bg-primary-soft', icon: 'bg-primary text-white', badge: 'bg-primary text-white', glyph: SyncOutlined },
    success: { border: 'border-success', band: 'bg-success-soft', icon: 'bg-success text-white', badge: 'bg-success text-white', glyph: CheckCircleFilled },
    danger: { border: 'border-danger', band: 'bg-danger-soft', icon: 'bg-danger text-white', badge: 'bg-danger text-white', glyph: CloseCircleFilled },
    warning: { border: 'border-warning', band: 'bg-warning-soft', icon: 'bg-warning text-white', badge: 'bg-warning text-white', glyph: ExclamationCircleFilled },
    neutral: { border: 'border-border', band: '', icon: 'bg-surface-sunken text-ink-muted', badge: 'bg-surface-sunken text-ink-muted', glyph: InfoCircleOutlined },
}
const look = computed(() => {
    if (tone.value !== 'neutral') return LOOKS[tone.value]
    if (managed.value) return { ...LOOKS.neutral, glyph: LockOutlined }
    if (showVersion.value && upToDate.value) return { ...LOOKS.neutral, icon: 'bg-success-soft text-success-ink', glyph: CheckOutlined }
    return LOOKS.neutral
})
/** The short text beside the title that names the state; none while loading, for a managed site or an unknown newest version. */
const badge = computed(() => {
    const of = (key, testid = 'core-update-state', params) => ({ text: t(`text.modules.core_update.${key}`, params), testid })
    if (state.error || !state.loaded || managed.value) return null
    if (tone.value === 'progress') return of('badge_progress')
    if (tone.value === 'success') return of('badge_done')
    if (tone.value === 'danger') return of('badge_failed')
    if (tone.value === 'warning') return of('badge_blocked')
    if (!showVersion.value) return null
    if (newer.value) return of('newer', 'core-update-newer', { version: state.info.latest })
    if (upToDate.value) return of('up_to_date', 'core-update-up-to-date')
    return null
})
/** The part under the header exists only when it has something to say. */
const hasBody = computed(() => {
    if (state.loading && !state.loaded) return true
    if (state.error) return true
    if (!state.loaded || managed.value) return false
    return !!running.value || !!result.text || blocked.value || !!reloadPrompt.mode
})
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
            const restored = !!task.result?.restored || kind === 'restore'
            result.ok = true
            result.text = restored
                ? t('text.modules.core_update.result.restored')
                : t('text.modules.core_update.result.updated', { version: task.result?.to || state.info.installed })
            // The update rebuilt the admin: this page still runs the old build until it reloads.
            if (!restored) startReloadCountdown()
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

const stopReloadTimer = () => {
    clearInterval(reloadTimer)
    reloadTimer = null
}

/**
 * Count down to the reload. Only showOutcome() starts it, so only for an update this page saw queued or running and
 * then read as succeeded; a page that loads later never reloads on its own.
 */
const startReloadCountdown = () => {
    stopReloadTimer()
    reloadPrompt.mode = 'countdown'
    reloadPrompt.seconds = RELOAD_SECONDS
    reloadPrompt.busy = false
    reloadTimer = setInterval(() => {
        if (disposed) return stopReloadTimer()
        reloadPrompt.seconds = Math.max(0, reloadPrompt.seconds - 1)
        if (reloadPrompt.seconds === 0) reloadNow()
    }, 1000)
}

/** Reload the admin now. A second call while the first is on its way does nothing. */
const reloadNow = async () => {
    if (reloadPrompt.busy) return
    reloadPrompt.busy = true
    stopReloadTimer()
    await reloadAdminBuild()
}

/** Stop the countdown; a plain reload button stays. */
const reloadLater = () => {
    if (reloadPrompt.busy) return
    stopReloadTimer()
    reloadPrompt.mode = 'later'
}

/**
 * Show the queued state at once and start following the new task.
 */
const follow = (res, kind) => {
    tracked = { uuid: String(res?.data?.data?.background_task_uuid || ''), kind }
    state.info = { ...state.info, can_update: false, can_overwrite: false, can_restore: false, running: { uuid: tracked.uuid, progress: 0, status: 'queued' } }
    schedule()
    load(true)
}

/**
 * Load the card. A silent refresh (the poll) keeps the last data when it fails. With `fresh` the API asks the hub for
 * the newest version now instead of answering from its hour-long cache (the page's reload button only; never the
 * mount, the poll or "check again").
 */
const load = async (silent = false, fresh = false) => {
    if (!silent) {
        state.loading = true
        state.error = ''
    }
    try {
        const res = await ajax.get(ENDPOINT, fresh ? { ...quiet, params: { refresh: 1 } } : quiet)
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

/** Open the update drawer; with `overwrite` it updates over the changed core files it lists. */
const openConfirm = (overwrite = false) => {
    const allowed = overwrite === true ? state.info.can_overwrite : state.info.can_update
    if (!allowed) return
    clearResult()
    confirm.overwrite = overwrite === true
    confirm.backup = false
    confirm.overwriteChecked = false
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
    if (!canSubmit.value || !state.info.latest) return
    confirm.loading = true
    confirm.error = ''
    const body = { version: state.info.latest, confirm_backup: true }
    if (confirm.overwrite) body.overwrite_modified = true
    try {
        const res = await ajax.post(ENDPOINT, body, quiet)
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
            if (preflight) state.info = { ...state.info, can_update: false, can_overwrite: false, preflight }
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

const copyCommand = async () => markCopied('command', await copyText(String(state.info.command || ''), commandCode))

/** Read the card again after a fix on the server; the button is busy until the answer arrives. */
const recheck = () => {
    if (state.loading) return
    clearResult()
    load()
}

/**
 * Reload from the page's reload button: the hub is asked for the newest version, and what is on screen stays until
 * the answer arrives (a failed read keeps it). A second call while one runs sends nothing.
 */
let refreshing = false
const refresh = async () => {
    if (refreshing || state.loading) return
    refreshing = true
    try {
        await load(state.loaded, true)
    } finally {
        refreshing = false
    }
}

// `info` lets the Modules page tell the upgrade-all band whether a newer iSPA CMS can be installed now.
defineExpose({ refresh, info: computed(() => state.info) })

const copyPrompt = async () => markCopied('prompt', await copyText(agentPrompt.value, promptPre))

onMounted(() => load())

onBeforeUnmount(() => {
    disposed = true
    clearTimeout(timer)
    stopReloadTimer()
    clearTimeout(copiedTimers.line)
    clearTimeout(copiedTimers.prompt)
    clearTimeout(copiedTimers.owner)
    clearTimeout(copiedTimers.command)
})
</script>
