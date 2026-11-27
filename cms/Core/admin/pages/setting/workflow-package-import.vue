<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <no-permission-error v-if="!allowed" data-testid="workflow-package-not-allowed" />
    <div v-else class="mx-auto max-w-3xl space-y-4" data-testid="workflow-package-page">
        <section class="i-box space-y-3">
            <router-link :to="{ name: 'workflows' }" class="text-sm" data-testid="package-back">
                {{ t('text.workflow.package.back') }}
            </router-link>
            <h1 class="m-0 text-lg font-semibold">{{ t('text.workflow.package.title') }}</h1>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.workflow.package.intro') }}</p>

            <template v-if="!installed">
                <!-- The native input is visually hidden: its own button text follows the browser language, not the admin's. -->
                <input
                    ref="fileInput"
                    type="file"
                    accept=".json,application/json"
                    class="sr-only"
                    :aria-label="t('text.workflow.package.choose_file')"
                    :disabled="busy"
                    data-testid="package-file"
                    @change="onFileChange"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <a-button :type="file ? 'default' : 'primary'" :disabled="busy" data-testid="package-pick" @click="fileInput && fileInput.click()">
                        {{ file ? t('text.workflow.package.choose_other') : t('text.workflow.package.choose_file') }}
                    </a-button>
                    <span v-if="file" class="min-w-0 break-all text-sm" data-testid="package-file-name">{{ file.name }}</span>
                </div>
                <p v-if="!file" class="m-0 text-xs text-ink-muted">{{ t('text.workflow.package.choose_hint') }}</p>
            </template>
        </section>

        <!-- Installed: the workflow exists and is switched off. -->
        <section v-if="installed" class="i-box space-y-3" role="status" data-testid="package-installed">
            <h2 class="m-0 text-base font-semibold text-green-700">{{ t('text.workflow.package.installed_title', { name: installed.name }) }}</h2>
            <p class="m-0 text-sm">{{ t('text.workflow.package.installed_off') }}</p>
            <div class="flex flex-wrap gap-2">
                <a-button type="primary" data-testid="package-setup" @click="setUpInstalled">{{ t('text.workflow.setup.action') }}</a-button>
                <a-button data-testid="package-open" @click="openInstalled">{{ t('text.workflow.package.open_designer') }}</a-button>
                <a-button data-testid="package-again" @click="reset">{{ t('text.workflow.package.install_another') }}</a-button>
            </div>
        </section>

        <template v-else>
            <section v-if="checking && !report" class="i-box flex items-center gap-3" role="status" data-testid="package-checking">
                <a-spin />
                <p class="m-0 text-sm">{{ t('text.workflow.package.checking') }}</p>
            </section>

            <a-alert
                v-if="error"
                type="error"
                show-icon
                :message="t(`text.workflow.package.error.${error}_title`)"
                :description="t(`text.workflow.package.error.${error}_hint`)"
                data-testid="package-error"
            >
                <template v-if="error === 'failed' || error === 'busy'" #action>
                    <a-button size="small" :disabled="busy" data-testid="package-retry" @click="check">{{ t('text.workflow.package.retry') }}</a-button>
                </template>
            </a-alert>

            <template v-if="report">
                <!-- What the workflow is and does. -->
                <section class="i-box space-y-3" data-testid="package-summary">
                    <div class="space-y-1">
                        <h2 class="m-0 break-words text-base font-semibold">{{ report.name || t('text.workflow.package.unnamed') }}</h2>
                        <p v-if="description" class="m-0 break-words text-sm text-ink-muted">{{ description }}</p>
                        <p class="m-0 text-xs text-ink-muted">{{ t('text.workflow.package.step_count', { count: summary.stepCount }) }}</p>
                    </div>
                    <div v-if="report.valid" class="space-y-1">
                        <p class="m-0 text-sm font-medium">{{ t('text.workflow.package.does_title') }}</p>
                        <ul class="m-0 list-disc space-y-1 pl-5 text-sm" data-testid="package-does">
                            <li v-for="name in summary.safety" :key="name" class="break-words">{{ t(`text.workflow.package.does.${name}`) }}</li>
                            <li v-if="summary.needsApproval" class="break-words">{{ t('text.workflow.package.does.approval') }}</li>
                            <li v-if="summary.hosts.length" class="break-words">
                                {{ t('text.workflow.package.does.hosts', { hosts: summary.hosts.join(', ') }) }}
                            </li>
                            <li v-if="summary.hostFromData" class="break-words">{{ t('text.workflow.package.does.host_from_data') }}</li>
                            <li v-if="summary.modules.length" class="break-words">
                                {{ t('text.workflow.package.does.modules', { modules: summary.modules.join(', ') }) }}
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Blocking problems of the package itself. -->
                <a-alert v-if="problems.length" type="error" show-icon :message="t('text.workflow.package.problems_title')" data-testid="package-problems">
                    <template #description>
                        <p class="m-0 text-sm">{{ t('text.workflow.package.problems_hint') }}</p>
                        <ul class="m-0 mt-2 list-disc space-y-1 pl-5 text-sm">
                            <li v-for="(row, index) in problems" :key="index" class="break-words" :data-code="row.code">
                                {{ row.text }}
                                <span v-if="row.steps.length" class="text-ink-muted">{{ t('text.workflow.package.at_steps', { steps: row.steps.join(', ') }) }}</span>
                            </li>
                        </ul>
                    </template>
                </a-alert>

                <template v-if="report.valid">
                    <!-- A workflow with the same key exists: install as a copy; the operator never types a key. -->
                    <a-alert
                        v-if="slugBlocked"
                        type="warning"
                        show-icon
                        :message="t(taken ? 'text.workflow.package.copy_title' : 'text.workflow.package.copy_title_invalid')"
                        data-testid="package-slug"
                    >
                        <!-- The button sits under the text: beside it, a narrow window squeezes the text into a thin column. -->
                        <template #description>
                            <p class="m-0 text-sm">{{ t('text.workflow.package.copy_hint') }}</p>
                            <a-button class="mt-2" type="primary" :loading="copying" :disabled="busy && !copying" data-testid="package-copy" @click="installAsCopy">
                                {{ t('text.workflow.package.copy_action') }}
                            </a-button>
                        </template>
                    </a-alert>
                    <a-alert
                        v-else-if="asSlug"
                        type="success"
                        show-icon
                        :message="t('text.workflow.package.copy_chosen_title')"
                        :description="t('text.workflow.package.copy_chosen_hint', { name: asName })"
                        data-testid="package-copy-chosen"
                    />
                    <a-alert
                        v-if="copyFailed"
                        type="error"
                        show-icon
                        :message="t('text.workflow.package.copy_failed_title')"
                        :description="t('text.workflow.package.copy_failed_hint')"
                        data-testid="package-copy-failed"
                    />

                    <!-- Things of this site the workflow refers to. -->
                    <section v-if="rows.length" class="i-box space-y-3" data-testid="package-bindings">
                        <div class="space-y-1">
                            <h3 class="m-0 text-sm font-semibold">{{ t('text.workflow.package.bindings_title') }}</h3>
                            <p class="m-0 text-sm text-ink-muted">{{ t('text.workflow.package.bindings_hint') }}</p>
                        </div>
                        <binding-field
                            v-for="row in rows"
                            :key="row.name"
                            :row="row"
                            :lists="lists"
                            :disabled="installing"
                            :can-manage-credentials="canManageCredentials"
                            @update:value="(value) => setValue(row.name, value)"
                            @reload="loadList"
                        />
                        <ul v-if="looseProblems.length" class="m-0 list-disc space-y-1 pl-5 text-sm text-red-600" role="alert" data-testid="package-binding-problems">
                            <li v-for="(row, index) in looseProblems" :key="index" class="break-words">{{ row.text }}</li>
                        </ul>
                    </section>

                    <a-alert v-if="warnings.length" type="warning" show-icon :message="t('text.workflow.package.warnings_title')" data-testid="package-warnings">
                        <template #description>
                            <ul class="m-0 list-disc space-y-1 pl-5 text-sm">
                                <li v-for="(row, index) in warnings" :key="index" class="break-words" :data-code="row.code">{{ row.text }}</li>
                            </ul>
                        </template>
                    </a-alert>

                    <!-- Safe test run. -->
                    <section class="i-box space-y-2" data-testid="package-dry-run">
                        <h3 class="m-0 text-sm font-semibold">{{ t('text.workflow.package.dry_run_title') }}</h3>
                        <p v-if="runState === 'none'" class="m-0 text-sm" data-testid="dry-run-none">{{ t('text.workflow.package.dry_run_none') }}</p>
                        <template v-else>
                            <p class="m-0 text-sm text-ink-muted">{{ t('text.workflow.package.dry_run_safe') }}</p>
                            <p v-if="blankSample" class="m-0 text-sm text-ink-muted" data-testid="dry-run-blank">{{ t('text.workflow.package.dry_run_blank') }}</p>
                            <p class="m-0 text-sm font-medium" :class="runState === 'completed' ? 'text-green-700' : 'text-amber-700'" data-testid="dry-run-state">
                                {{ t(`text.workflow.package.dry_run_${runState}`, { count: runRows.length }) }}
                            </p>
                            <details :open="runRows.length <= 8" class="text-sm">
                                <summary class="cursor-pointer py-1">{{ t('text.workflow.package.dry_run_steps', { count: runRows.length }) }}</summary>
                                <ol class="m-0 mt-1 list-decimal space-y-2 pl-5">
                                    <li v-for="row in runRows" :key="row.code" class="break-words" :data-mode="row.mode">
                                        <span class="font-medium">{{ row.name }}</span>
                                        <span class="block text-ink-muted">
                                            <template v-if="row.failed">{{ t('text.workflow.package.dry_run_row_failed') }}</template>
                                            <template v-else-if="row.mode === 'ran'">{{ t('text.workflow.package.dry_run_row_ran') }}</template>
                                            <template v-else-if="row.mode === 'approval'">{{ t('text.workflow.package.dry_run_row_approval') }}</template>
                                            <template v-else>
                                                {{ t('text.workflow.package.dry_run_row_would', { what: wouldText(row) }) }}
                                            </template>
                                        </span>
                                    </li>
                                </ol>
                            </details>
                        </template>
                    </section>
                </template>

                <!-- The one primary action. -->
                <section class="i-box space-y-2" data-testid="package-install-box">
                    <a-alert
                        v-if="installError"
                        type="error"
                        show-icon
                        :message="t('text.workflow.package.install_failed_title')"
                        :description="t(`text.workflow.package.install_failed_${installError}`)"
                        data-testid="package-install-error"
                    />
                    <p class="m-0 text-sm">{{ t('text.workflow.package.install_note') }}</p>
                    <ul v-if="!install.enabled && !checking" class="m-0 list-disc space-y-1 pl-5 text-sm text-amber-700" data-testid="package-blockers">
                        <li v-for="blocker in install.blockers" :key="blocker.code" class="break-words">
                            {{ t(`text.workflow.package.blocker.${blocker.code}`, { count: blocker.count }) }}
                        </li>
                    </ul>
                    <a-button type="primary" :disabled="!install.enabled" :loading="installing" data-testid="package-install" @click="doInstall">
                        {{ t('text.workflow.package.install') }}
                    </a-button>
                </section>
            </template>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { rule } from '@/mixins'
import BindingField from '../../components/workflow-package/binding-field.vue'
import { fetchAgentProfiles, fetchCredentials, fetchWorkflows, installWorkflowPackage, previewWorkflowPackage } from '../../workflow-package-api'
import {
    bindingPayload,
    bindingRows,
    copyName,
    defaultBindingValues,
    describeProblems,
    dryRunRows,
    dryRunState,
    fileRefusal,
    findCopySlug,
    hasSample,
    installState,
    looseBindingProblems,
    packageSummary,
    parsePackage,
    slugTaken,
    stepNamesOf,
} from '../../workflow-package-report'

/**
 * Bring a workflow from another site into this one: choose the package file, read in plain words what it does and
 * needs, pick the things of this site it refers to, see the safe test run, install. Installing always creates a new
 * workflow that is switched off.
 */
defineOptions({ name: 'SettingWorkflowPackageImport' })

const { t } = useI18n()
const router = useRouter()

/** The API refuses everyone without the right to create workflows; the page says so instead of failing later. */
const allowed = computed(() => rule('workflow_project.create'))
const canManageCredentials = computed(() => rule('workflow_credential.manage') && router.hasRoute('workflow-credentials'))

const fileInput = ref(null)
const file = ref(null)
/** Parsed file, only used for step names and the description; the server checks the file itself. */
const pkg = ref(null)
const report = ref(null)
const checking = ref(false)
/** `not_a_package`, `too_large`, `unreadable`, `forbidden`, `busy` or `failed`. */
const error = ref('')
const values = reactive({})
const asSlug = ref('')
const copying = ref(false)
const copyFailed = ref(false)
const installing = ref(false)
const installError = ref('')
const installed = ref(null)

const lists = reactive({
    credentials: { items: [], loading: false, failed: false, asked: false },
    profiles: { items: [], loading: false, failed: false, asked: false },
    workflows: { items: [], loading: false, failed: false, asked: false },
})
const loaders = { credentials: fetchCredentials, profiles: fetchAgentProfiles, workflows: fetchWorkflows }
const LIST_OF_KIND = { credential: 'credentials', agent_profile: 'profiles', workflow: 'workflows' }

const busy = computed(() => checking.value || copying.value || installing.value)
const names = computed(() => stepNamesOf(pkg.value))
const description = computed(() => (typeof pkg.value?.workflow?.description === 'string' ? pkg.value.workflow.description : ''))
const ctx = computed(() => ({ t, names: names.value, pkg: pkg.value }))

const summary = computed(() => packageSummary(report.value))
const problems = computed(() => describeProblems(report.value?.problems, ctx.value))
const warnings = computed(() => describeProblems(report.value?.warnings, ctx.value))
const looseProblems = computed(() => describeProblems(looseBindingProblems(report.value), ctx.value))
const rows = computed(() => bindingRows(report.value, values, names.value))
const slugBlocked = computed(() => (report.value?.slug_problems || []).length > 0)
const taken = computed(() => slugTaken(report.value))
const runState = computed(() => dryRunState(report.value))
const runRows = computed(() => dryRunRows(report.value, names.value))
const install = computed(() => installState(report.value, { busy: busy.value, rows: rows.value }))

/** "would …" sentence of a described step, from its safety classes. */
const wouldText = (row) => (row.safety.length ? row.safety.map((name) => t(`text.workflow.package.would.${name}`)).join(', ') : t('text.workflow.package.would.other'))

/** The file carries no sample record: the test run then walks the workflow with a blank one. */
const blankSample = computed(() => !!pkg.value && !hasSample(pkg.value))

/** Own name of the copy being installed ('' when it is not a copy). */
const asName = computed(() => (asSlug.value ? copyName(report.value?.name, asSlug.value, t) : ''))

const options = (slug = asSlug.value) => ({ bindings: bindingPayload(report.value?.bindings, values), asSlug: slug, blankSample: blankSample.value })

/** Map a failed request to the page's own message. */
function requestError(e) {
    const status = e?.response?.status
    if (status === 403) return 'forbidden'
    if (status === 429) return 'busy'
    if (status === 422) return fileRefusal(file.value, pkg.value)
    return 'failed'
}

let run = 0
async function check() {
    if (!file.value) return
    const current = ++run
    checking.value = true
    error.value = ''
    try {
        const data = await previewWorkflowPackage(file.value, options())
        if (current !== run) return
        const first = !report.value
        report.value = data
        if (first) await prepareBindings()
    } catch (e) {
        if (current !== run) return
        error.value = requestError(e)
    } finally {
        if (current === run) checking.value = false
    }
}

/** Load the lists the bindings need and pre-select a credential of the same name (the server's own default). */
async function prepareBindings() {
    const kinds = new Set((report.value?.bindings || []).map((binding) => LIST_OF_KIND[binding.kind]).filter(Boolean))
    await Promise.all([...kinds].map((key) => loadList(key)))
    Object.entries(defaultBindingValues(report.value?.bindings, lists.credentials.items)).forEach(([name, value]) => {
        if (!values[name]) values[name] = value
    })
}

async function loadList(key) {
    const target = lists[key]
    if (!target || target.loading) return
    target.loading = true
    target.failed = false
    try {
        target.items = await loaders[key]()
    } catch (e) {
        target.failed = true
        target.items = []
    } finally {
        target.loading = false
        target.asked = true
    }
}

let timer = null
function setValue(name, value) {
    values[name] = value
    installError.value = ''
    clearTimeout(timer)
    // A pasted record code is checked while typing; a short pause keeps the number of requests low.
    timer = setTimeout(check, 350)
}
onBeforeUnmount(() => clearTimeout(timer))

async function onFileChange(event) {
    const chosen = event.target.files?.[0] || null
    event.target.value = ''
    if (!chosen) return
    reset()
    file.value = chosen
    pkg.value = chosen.size > 0 ? parsePackage(await chosen.text().catch(() => '')) : null
    await check()
}

function reset() {
    run += 1
    clearTimeout(timer)
    file.value = null
    pkg.value = null
    report.value = null
    checking.value = false
    error.value = ''
    Object.keys(values).forEach((name) => delete values[name])
    asSlug.value = ''
    copying.value = false
    copyFailed.value = false
    installing.value = false
    installError.value = ''
    installed.value = null
}

/** Ask the server for a free copy key; the operator only confirms. */
async function installAsCopy() {
    if (copying.value || !file.value) return
    copying.value = true
    copyFailed.value = false
    error.value = ''
    const current = ++run
    try {
        const found = await findCopySlug(report.value.source_slug, (slug) => previewWorkflowPackage(file.value, options(slug)))
        if (current !== run) return
        if (!found) {
            copyFailed.value = true
            return
        }
        asSlug.value = found.slug
        report.value = found.report
    } catch (e) {
        if (current === run) error.value = requestError(e)
    } finally {
        copying.value = false
    }
}

async function doInstall() {
    if (installing.value || !install.value.enabled) return
    installing.value = true
    installError.value = ''
    try {
        const data = await installWorkflowPackage(file.value, { ...options(), asName: asName.value, blankSample: false })
        installed.value = { slug: data.slug, name: data.name || report.value?.name || '' }
    } catch (e) {
        installing.value = false
        const body = e?.response?.data
        if (e?.response?.status === 422 && body?.data && typeof body.data === 'object') {
            // The site changed since the check (for example the copy key was taken): show the new report.
            report.value = { ...body.data, dry_run: report.value?.dry_run ?? null }
            if ((body.data.slug_problems || []).length) asSlug.value = ''
            installError.value = 'changed'
        } else {
            installError.value = e?.response?.status === 403 ? 'forbidden' : e?.response?.status === 429 ? 'busy' : 'other'
        }
    }
}

/** The choices only this site can make (who reviews, when it runs) come first. */
function setUpInstalled() {
    router.push({ name: 'workflows-setup', params: { project: installed.value.slug } })
}

function openInstalled() {
    router.push({ name: 'workflows', query: { open: installed.value.slug } })
}
</script>
