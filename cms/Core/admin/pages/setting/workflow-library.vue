<!--
  Workflow template library: sample workflows downloaded from the iSPA hub. The list shows what each template does
  and needs; the drawer shows what it may do before anything is installed. An install always arrives switched off and
  opens the setup screen; an earlier install is never overwritten (a newer version is installed as a copy).
-->
<template>
    <no-permission-error v-if="!allowed" data-testid="workflow-library-not-allowed" />
    <div v-else class="flex flex-col gap-4" data-testid="workflow-library-page">
        <section class="i-box flex flex-col gap-3">
            <router-link :to="{ name: 'setting-workflows-manage' }" class="text-sm" data-testid="library-back">{{ t('text.workflow.package.back') }}</router-link>
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1 basis-64">
                    <h1 class="m-0 break-words text-lg font-semibold">{{ t('text.workflow.library.title') }}</h1>
                    <p class="m-0 mt-1 text-sm text-ink-muted">{{ t('text.workflow.library.subtitle') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a-button :loading="stage === 'loading' && refreshing" :disabled="stage === 'loading'" data-testid="library-refresh" @click="load(true)">
                        {{ t('text.workflow.library.refresh') }}
                    </a-button>
                    <a-button v-if="canInstall" data-testid="library-import-file" @click="router.push({ name: 'setting-workflows-import' })">
                        {{ t('text.workflow.library.import_file') }}
                    </a-button>
                </div>
            </div>

            <div v-if="stage === 'ready' && items.length" class="flex flex-wrap items-center gap-2">
                <a-input
                    v-model:value="filters.search"
                    allow-clear
                    class="min-w-0 flex-1 basis-56"
                    :placeholder="t('text.workflow.library.search')"
                    :aria-label="t('text.workflow.library.search')"
                    data-testid="library-search"
                />
                <div v-if="groups.length" class="flex flex-wrap gap-2" role="group" :aria-label="t('text.workflow.library.groups')">
                    <button
                        v-for="chip in chips"
                        :key="chip.value"
                        type="button"
                        class="min-h-8 cursor-pointer rounded-full border border-solid px-3 py-1 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700"
                        :class="filters.group === chip.value ? 'border-cyan-700 bg-cyan-700 text-white' : 'border-gray-300 bg-white text-gray-800 hover:border-cyan-600'"
                        :aria-pressed="filters.group === chip.value ? 'true' : 'false'"
                        data-testid="library-group"
                        :data-group="chip.value"
                        @click="filters.group = chip.value"
                    >
                        {{ chip.label }}
                    </button>
                </div>
                <a-checkbox v-model:checked="filters.onlyAvailable" data-testid="library-only-available">
                    {{ t('text.workflow.library.only_available') }}
                </a-checkbox>
            </div>
        </section>

        <div v-if="stage === 'loading'" class="i-box flex items-center gap-3" role="status" data-testid="library-loading">
            <a-spin />
            <p class="m-0 text-sm">{{ t('text.workflow.library.loading') }}</p>
        </div>

        <a-alert
            v-else-if="stage === 'error'"
            type="error"
            show-icon
            :message="t(`text.workflow.library.error.${listError.kind}`)"
            :description="t(`text.workflow.library.error_hint.${listError.kind}`)"
            data-testid="library-load-error"
            :data-kind="listError.kind"
        >
            <template v-if="listError.retry" #action>
                <a-button size="small" data-testid="library-retry" @click="load(true)">{{ t('text.workflow.package.retry') }}</a-button>
            </template>
        </a-alert>

        <section v-else-if="!items.length" class="i-box flex flex-col gap-1" data-testid="library-empty">
            <h2 class="m-0 text-base font-semibold">{{ t('text.workflow.library.empty_title') }}</h2>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.workflow.library.empty_hint') }}</p>
        </section>

        <template v-else>
            <section v-if="!visible.length" class="i-box flex flex-col items-start gap-2" data-testid="library-no-match">
                <p class="m-0 text-sm">{{ t('text.workflow.library.no_match') }}</p>
                <p v-if="hiddenCount" class="m-0 text-sm text-ink-muted">{{ t('text.workflow.library.hidden_unavailable', { count: hiddenCount }) }}</p>
                <a-button data-testid="library-show-all" @click="showAll">{{ t('text.workflow.library.show_all') }}</a-button>
            </section>

            <ul class="m-0 grid list-none gap-3 p-0 sm:grid-cols-2 xl:grid-cols-3" data-testid="library-cards">
                <li v-for="item in visible" :key="item.key" class="flex min-w-0">
                    <button
                        type="button"
                        class="flex w-full min-w-0 cursor-pointer flex-col gap-2 rounded-xl border px-4 py-3 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700"
                        :class="[
                            item.available ? 'border-solid bg-white hover:border-cyan-500' : 'border-dashed border-gray-300 bg-gray-50',
                            selectedKey === item.key ? 'border-cyan-700 ring-1 ring-cyan-700' : item.available ? 'border-gray-200' : '',
                        ]"
                        :aria-haspopup="'dialog'"
                        data-testid="library-card"
                        :data-key="item.key"
                        :data-available="item.available ? 'yes' : 'no'"
                        @click="openDetail(item, $event)"
                    >
                        <span class="flex w-full items-start justify-between gap-2">
                            <span class="min-w-0 break-words text-[15px] font-semibold" :class="item.available ? 'text-gray-900' : 'text-gray-600'">{{ item.name }}</span>
                            <a-tag v-if="item.installed" color="green" class="!m-0 shrink-0" data-testid="library-card-installed">{{ t('text.workflow.library.installed') }}</a-tag>
                        </span>
                        <span v-if="item.description" class="break-words text-sm text-gray-600">{{ item.description }}</span>
                        <template v-if="item.available">
                            <span v-if="item.tags.length" class="flex flex-wrap gap-1.5">
                                <a-tag v-for="tag in item.tags" :key="tag.key" :color="tagColor(tag.key)" class="!m-0" data-testid="library-card-tag" :data-tag="tag.key">{{ tag.label }}</a-tag>
                            </span>
                            <span class="mt-auto flex w-full flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-gray-600">
                                <span v-if="item.modules.length" class="break-words" data-testid="library-card-needs">
                                    {{ t('text.workflow.library.needs_modules', { modules: moduleNames(item.modules) }) }} ✓
                                </span>
                                <span v-if="item.update_available" class="font-semibold text-amber-700" data-testid="library-card-update">{{ t('text.workflow.library.update_available') }}</span>
                                <span v-else-if="item.needs_account && !item.installed">{{ t('text.workflow.library.needs_account') }}</span>
                            </span>
                        </template>
                        <span v-else class="break-words text-xs text-amber-800" data-testid="library-card-missing">
                            {{ t('text.workflow.library.needs_modules_first', { modules: moduleNames(item.missing_modules) }) }}
                        </span>
                    </button>
                </li>
            </ul>
        </template>

        <a-drawer
            :open="drawer.open"
            :width="drawerWidth"
            :title="selected?.name || ''"
            destroy-on-close
            data-testid="library-drawer"
            @close="closeDetail"
            @after-open-change="onDrawerToggle"
        >
            <div v-if="selected" ref="panel" class="flex flex-col gap-4" data-testid="library-detail" :data-key="selected.key">
                <p class="m-0 text-xs text-gray-600" data-testid="library-detail-versions">{{ versionLine }}</p>

                <div v-if="drawer.stage === 'loading'" class="flex items-center gap-3" role="status" data-testid="library-detail-loading">
                    <a-spin />
                    <p class="m-0 text-sm">{{ t('text.workflow.library.detail_loading') }}</p>
                </div>

                <a-alert
                    v-else-if="drawer.stage === 'error'"
                    type="error"
                    show-icon
                    :message="t(`text.workflow.library.error.${drawer.error.kind}`)"
                    :description="t(`text.workflow.library.error_hint.${drawer.error.kind}`)"
                    data-testid="library-detail-error"
                    :data-kind="drawer.error.kind"
                >
                    <template v-if="drawer.error.retry" #action>
                        <a-button size="small" data-testid="library-detail-retry" @click="loadDetail">{{ t('text.workflow.package.retry') }}</a-button>
                    </template>
                </a-alert>

                <template v-else-if="detail">
                    <section v-if="detail.does.length" class="flex flex-col gap-1.5">
                        <h3 class="m-0 text-sm font-semibold">{{ t('text.workflow.library.does_title') }}</h3>
                        <ol class="m-0 list-decimal space-y-1 pl-5 text-sm" data-testid="library-detail-does">
                            <li v-for="(line, index) in detail.does" :key="index" class="break-words">{{ line }}</li>
                        </ol>
                    </section>

                    <section v-if="detail.asks.length" class="flex flex-col gap-1.5">
                        <h3 class="m-0 text-sm font-semibold">{{ t('text.workflow.library.asks_title') }}</h3>
                        <ul class="m-0 list-disc space-y-1 pl-5 text-sm" data-testid="library-detail-asks">
                            <li v-for="(line, index) in detail.asks" :key="index" class="break-words">{{ line }}</li>
                        </ul>
                    </section>

                    <section class="flex flex-col gap-1.5">
                        <h3 class="m-0 text-sm font-semibold">{{ t('text.workflow.library.may_title') }}</h3>
                        <ul class="m-0 list-disc space-y-1 pl-5 text-sm" data-testid="library-detail-may">
                            <li v-for="(line, index) in detail.may" :key="index" class="break-words">{{ line }}</li>
                        </ul>
                        <p v-if="detail.signature_verified" class="m-0 text-xs text-green-800" data-testid="library-detail-signed">✓ {{ t('text.workflow.library.signed') }}</p>
                    </section>

                    <section
                        v-if="action === 'install_copy' || action === 'update'"
                        class="flex flex-col gap-1.5 rounded-lg border border-solid border-amber-200 bg-amber-50 px-3 py-2.5 text-amber-900"
                        data-testid="library-detail-update"
                    >
                        <h3 class="m-0 text-sm font-semibold text-amber-900">{{ t('text.workflow.library.update_title', { version: detail.latest_version_label }) }}</h3>
                        <p v-if="detail.notes" class="m-0 whitespace-pre-line break-words text-sm" data-testid="library-detail-notes">{{ detail.notes }}</p>
                        <p v-if="copyReason" class="m-0 break-words text-sm" data-testid="library-detail-copy-reason" :data-reason="copyReason">{{ t(`text.workflow.library.copy_reason.${copyReason}`) }}</p>
                        <p v-else class="m-0 break-words text-sm" data-testid="library-detail-update-in-place">{{ t('text.workflow.library.update_in_place_note') }}</p>
                    </section>

                    <p v-if="action === 'open'" class="m-0 break-words text-sm" data-testid="library-detail-installed">
                        {{ t(detail.installed.is_active ? 'text.workflow.library.installed_on' : 'text.workflow.library.installed_off', { name: detail.installed.name }) }}
                    </p>

                    <a-alert
                        v-if="action === 'unavailable'"
                        type="warning"
                        show-icon
                        :message="t('text.workflow.library.needs_modules_first', { modules: moduleNames(detail.missing_modules) })"
                        :description="t('text.workflow.library.needs_modules_hint')"
                        data-testid="library-detail-unavailable"
                    />

                    <a-alert
                        v-if="installError"
                        type="error"
                        show-icon
                        :message="t(`text.workflow.library.error.${installError.kind}`)"
                        data-testid="library-install-error"
                        :data-kind="installError.kind"
                    >
                        <template #description>
                            <p class="m-0 break-words text-sm">
                                {{
                                    installError.kind === 'not_available' && installError.missingModules.length
                                        ? t('text.workflow.library.needs_modules_first', { modules: moduleNames(installError.missingModules) })
                                        : t(`text.workflow.library.error_hint.${installError.kind}`)
                                }}
                            </p>
                            <ul v-if="installProblems.length" class="m-0 mt-1 list-disc space-y-1 pl-5 text-sm" data-testid="library-install-problems">
                                <li v-for="(row, index) in installProblems" :key="index" class="break-words">
                                    {{ row.steps.length ? `${row.text} ${t('text.workflow.package.at_steps', { steps: row.steps.join(', ') })}` : row.text }}
                                </li>
                            </ul>
                        </template>
                    </a-alert>

                    <div v-if="action === 'install' || action === 'install_copy' || action === 'update'" class="flex flex-col gap-2">
                        <template v-if="canInstall">
                            <div class="flex flex-wrap gap-2">
                                <a-button
                                    type="primary"
                                    size="large"
                                    :loading="installing"
                                    :disabled="installing || installBlocked"
                                    data-testid="library-install"
                                    :data-action="action"
                                    @click="install"
                                >
                                    {{ t(INSTALL_LABELS[action]) }}
                                </a-button>
                                <a-button v-if="action !== 'install'" size="large" :disabled="installing" data-testid="library-open-current" @click="openDesigner">
                                    {{ t('text.workflow.library.open_current') }}
                                </a-button>
                            </div>
                            <p class="m-0 text-xs text-gray-600" data-testid="library-install-footnote">
                                {{ t(action === 'update' ? 'text.workflow.library.update_footnote' : 'text.workflow.library.install_footnote') }}
                            </p>
                        </template>
                        <p v-else class="m-0 text-sm text-gray-600" data-testid="library-install-not-allowed">{{ t('text.workflow.library.install_not_allowed') }}</p>
                    </div>

                    <div v-if="action === 'open'" class="flex flex-wrap gap-2">
                        <a-button type="primary" size="large" data-testid="library-open-setup" @click="openSetup">{{ t('text.workflow.library.open_setup') }}</a-button>
                        <a-button size="large" data-testid="library-open-designer" @click="openDesigner">{{ t('text.workflow.library.open_designer') }}</a-button>
                    </div>
                </template>
            </div>
        </a-drawer>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { rule } from '@/mixins'
import {
    copyNote,
    fetchLibrary,
    fetchTemplate,
    filterTemplates,
    groupsOf,
    humanize,
    installTemplate,
    libraryError,
    mainAction,
    moduleKey,
    reportRows,
    stepNamesOf,
    tagColor,
    versionParts,
} from '../../workflow-library-api'
import { describeProblems } from '../../workflow-package-report'

defineOptions({ name: 'WorkflowLibrary' })

const { t } = useI18n()
const router = useRouter()

const allowed = computed(() => rule('workflow_project.viewAny'))
/** Installing a template and bringing in a file both create a workflow. */
const canInstall = computed(() => rule('workflow_project.create'))

// ---------------------------------------------------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------------------------------------------------

const stage = ref('loading')
const refreshing = ref(false)
const items = ref([])
const listError = ref(null)
const filters = reactive({ search: '', group: '', onlyAvailable: true })

/** Kinds the list has its own sentence for; anything else reads as a plain failure. */
const LIST_KINDS = ['unreachable', 'forbidden', 'busy', 'failed']
const DETAIL_KINDS = ['unreachable', 'not_found', 'untrusted', 'forbidden', 'busy', 'failed']
const INSTALL_KINDS = ['unreachable', 'not_found', 'untrusted', 'not_available', 'not_installable', 'up_to_date', 'changed', 'forbidden', 'busy', 'failed']
const INSTALL_LABELS = {
    install: 'text.workflow.library.install',
    install_copy: 'text.workflow.library.install_copy',
    update: 'text.workflow.library.update_in_place',
}
const known = (error, kinds) => ({ ...error, kind: kinds.includes(error.kind) ? error.kind : 'failed' })

const load = async (refresh = false) => {
    if (stage.value === 'loading' && refreshing.value) return
    refreshing.value = refresh === true
    stage.value = 'loading'
    try {
        items.value = await fetchLibrary(refresh === true)
        if (filters.group && !groupsOf(items.value).includes(filters.group)) filters.group = ''
        stage.value = 'ready'
    } catch (error) {
        listError.value = known(libraryError(error), LIST_KINDS)
        stage.value = 'error'
    } finally {
        refreshing.value = false
    }
}

/** A translated text, or '' when the key has none (the missing handler answers with the key or its last segment). */
const translated = (key, missing) => {
    const value = t(key)
    return value && value !== key && value !== missing ? value : ''
}

const groups = computed(() => groupsOf(items.value))
const groupLabel = (group) => translated(`text.workflow.library.group.${group}`, humanize(group)) || humanize(group)
const chips = computed(() => [{ value: '', label: t('text.workflow.library.group_all') }, ...groups.value.map((group) => ({ value: group, label: groupLabel(group) }))])

const moduleName = (name) => translated(`nav.module.${moduleKey(name)}.name`, 'Name') || humanize(name)
const moduleNames = (names) => names.map(moduleName).join(', ')

const visible = computed(() => filterTemplates(items.value, filters))
/** Templates that match the search and group but are hidden because their modules are off. */
const hiddenCount = computed(() => (filters.onlyAvailable ? filterTemplates(items.value, { ...filters, onlyAvailable: false }).length - visible.value.length : 0))
const showAll = () => Object.assign(filters, { search: '', group: '', onlyAvailable: false })

// ---------------------------------------------------------------------------------------------------------------------
// Detail drawer
// ---------------------------------------------------------------------------------------------------------------------

const drawer = reactive({ open: false, stage: 'loading', error: null })
const selectedKey = ref('')
const detail = ref(null)
const installing = ref(false)
const installError = ref(null)
const panel = ref(null)
let opener = null

const drawerWidth = computed(() => (typeof window !== 'undefined' && window.innerWidth < 560 ? '100%' : 440))
const selected = computed(() => detail.value || items.value.find((item) => item.key === selectedKey.value) || null)
const action = computed(() => mainAction(detail.value))
const copyReason = computed(() => copyNote(detail.value))
const versionLine = computed(() =>
    versionParts(selected.value)
        .map((part) => t(`text.workflow.library.${part.key}`, { value: part.value }))
        .join(' · ')
)

const loadDetail = async () => {
    const key = selectedKey.value
    if (!key) return
    drawer.stage = 'loading'
    drawer.error = null
    try {
        const row = await fetchTemplate(key)
        if (selectedKey.value !== key) return
        detail.value = row
        drawer.stage = 'ready'
    } catch (error) {
        if (selectedKey.value !== key) return
        drawer.error = known(libraryError(error), DETAIL_KINDS)
        drawer.stage = 'error'
    }
}

/** Re-read the template after a refused update, keeping the refusal on screen. */
const refreshAfterRefusal = async () => {
    const key = selectedKey.value
    try {
        const row = await fetchTemplate(key)
        if (selectedKey.value === key) detail.value = row
    } catch (error) {
        // The refusal already says what happened; the drawer keeps what it showed.
    }
}

/**
 * Keyboard inside the open drawer: Tab stays among its controls (the page behind is covered), and Escape closes it
 * even after the focused button was switched off.
 */
const onDrawerKey = (event) => {
    const root = panel.value?.closest?.('.ant-drawer-content')
    if (!root) return
    if (event.key === 'Escape') {
        if (!root.contains(document.activeElement)) closeDetail()
        return
    }
    if (event.key !== 'Tab') return
    const stops = [...root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter((el) => !el.disabled && el.getClientRects().length)
    if (!stops.length) return
    const first = stops[0]
    const last = stops[stops.length - 1]
    const inside = root.contains(document.activeElement)
    if (event.shiftKey ? !inside || document.activeElement === first : !inside || document.activeElement === last) {
        event.preventDefault()
        ;(event.shiftKey ? last : first).focus()
    }
}
const listenKeys = (on) => typeof document !== 'undefined' && document[on ? 'addEventListener' : 'removeEventListener']('keydown', onDrawerKey)
onBeforeUnmount(() => listenKeys(false))

const openDetail = (item, event) => {
    if (installing.value) return
    opener = event?.currentTarget || null
    selectedKey.value = item.key
    detail.value = null
    installError.value = null
    drawer.open = true
    listenKeys(true)
    loadDetail()
}

const closeDetail = () => {
    if (installing.value) return
    drawer.open = false
}

/** Closed: the card that opened the drawer gets the keyboard focus back. */
const onDrawerToggle = (open) => {
    if (open) return
    listenKeys(false)
    selectedKey.value = ''
    detail.value = null
    nextTick(() => opener?.focus?.())
}

/** A bundle that failed the signature check, or modules that are off: pressing again cannot help. */
const installBlocked = computed(() => !!installError.value && !installError.value.retry && installError.value.kind !== 'forbidden')
const installProblems = computed(() =>
    installError.value?.report ? describeProblems(reportRows(installError.value.report), { t, names: stepNamesOf(detail.value) }).filter((row) => row.known) : []
)

const install = async () => {
    if (installing.value || !detail.value || !canInstall.value) return
    installing.value = true
    installError.value = null
    try {
        const result = await installTemplate(detail.value.key, { asCopy: action.value === 'install_copy' })
        // Stays busy on success: the setup screen opens next (after an update it shows any new question).
        await router.push({ name: 'setting-workflows-setup', params: { project: result.project_id } })
    } catch (error) {
        installError.value = known(libraryError(error), INSTALL_KINDS)
        installing.value = false
        // The install changed under us or is already current: show the template as it is now.
        if (['up_to_date', 'changed'].includes(installError.value.kind)) refreshAfterRefusal()
    }
}

const openSetup = () => router.push({ name: 'setting-workflows-setup', params: { project: detail.value.installed.project_id } })
const openDesigner = () => router.push({ name: 'setting-workflows-manage', query: { open: detail.value.installed.slug } })

if (allowed.value) load()
</script>
