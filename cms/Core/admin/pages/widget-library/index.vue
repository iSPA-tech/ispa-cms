<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    "Thư viện widget": every widget this site can use, one card each. An operator sees what a widget looks like, where
    it comes from (the shared catalog or this site's own copy), whether it is hidden on the site and where it is used,
    and from here previews it, adds it to a page or a shared area, edits its design, or hides it on the site.

    The screen belongs to Core and works with the Widget module off: the shared-area actions appear only when that
    module is on and the active theme has at least one shared area. Ids and keys never reach the screen; the only
    version shown is the one an update leads to ("Cập nhật lên bản N").

    With `widget.hub` the screen has two tabs: "Trên site" (the widgets of this site) and "Trên hub" (widgets of the hub
    this site does not have yet, with "Tải về"). The hub list is read in the background and never holds up "Trên site";
    when the hub cannot be reached "Trên hub" says so. A downloaded widget with a newer version on the hub shows
    "Có bản mới" (which starts the update) and "Cập nhật lên bản N" in its menu. Cards show a stored still picture; the
    live preview opens only from the picture or "Xem trước".
-->
<template>
    <template v-if="rule('widget.viewAny')">
        <div class="mx-auto max-w-7xl space-y-4">
            <breadcrumbs
                :items="[
                    {
                        icon: 'puzzle-outline',
                        text: $t('nav.a.widget-library'),
                        title: $t('nav.a.widget-library'),
                    },
                ]"
            >
                <template #description>
                    {{ $t(showArea ? 'text.widget_library.intro' : 'text.widget_library.intro_pages') }}
                </template>
            </breadcrumbs>

            <a-tabs v-if="canHub" v-model:activeKey="tab" class="[&_.ant-tabs-nav]:mb-0">
                <a-tab-pane key="site" :tab="$t('text.widget_library.tab_site')" />
                <a-tab-pane key="hub" :tab="$t('text.widget_library.tab_hub')" />
            </a-tabs>

            <template v-if="tab === 'hub'">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="m-0 min-w-0 flex-1 basis-56 text-sm text-slate-600">{{ $t('text.widget_library.hub_intro') }}</p>
                    <i-button
                        class="min-h-9"
                        data-test="hub-refresh"
                        :disabled="hubState === 'loading'"
                        :loading="hubState === 'loading'"
                        native-type="button"
                        @click="loadHub({ refresh: true })"
                    >
                        {{ $t('text.widget_library.hub_refresh') }}
                    </i-button>
                </div>
                <p v-if="hubState === 'loading'" class="m-0 text-sm text-slate-500" data-test="hub-loading">
                    {{ $t('text.widget_library.loading') }}
                </p>
                <p
                    v-else-if="hubState === 'failed'"
                    class="m-0 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600"
                    data-test="hub-unreachable"
                    role="status"
                >
                    {{ $t(hubErrorKey) }}
                </p>
                <p
                    v-else-if="hubAvailable.length === 0"
                    class="m-0 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500"
                    data-test="hub-empty"
                >
                    {{ $t('text.widget_library.hub_empty') }}
                </p>
                <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-test="hub-grid">
                    <hub-card
                        v-for="entry in hubAvailable"
                        :key="entry.widget_key"
                        :entry="entry"
                        :final-reason="hubRefusals[entry.widget_key] ?? ''"
                        @refused="hubRefusals = { ...hubRefusals, [entry.widget_key]: $event }"
                        @done="hubChanged($event, 'text.widget_library.hub_downloaded')"
                    />
                </div>
            </template>

            <template v-else>
                <!-- "Cập nhật tất cả" (upgrade-all spec 2026-11-26): every hub widget of this site that has a newer version -->
                <update-batch-panel v-if="batchAllowed" ref="batchPanel" @state="onBatchState" />
                <div v-if="batchAllowed && widgetUpdates > 0" class="flex justify-end">
                    <i-button
                        native-type="button"
                        variant="primary"
                        :disabled="batchOpen"
                        data-test="widget-update-all"
                        @click="batchDialogOpen = true"
                    >
                        {{ $t('text.update_batch.start_kind', { count: widgetUpdates }) }}
                    </i-button>
                </div>
                <update-batch-dialog
                    v-if="batchAllowed"
                    :open="batchDialogOpen"
                    :kinds="['widget']"
                    @close="batchDialogOpen = false"
                    @started="onBatchStarted"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <label class="sr-only" for="widget-library-search">{{ $t('text.widget_library.search') }}</label>
                    <a-input
                        id="widget-library-search"
                        v-model:value="search"
                        allow-clear
                        class="min-w-0 flex-1 basis-56 sm:max-w-xs"
                        data-test="library-search"
                        :placeholder="$t('text.widget_library.search')"
                    />
                    <div class="flex flex-wrap gap-1.5" role="group" :aria-label="$t('text.widget_library.filter_label')">
                        <button
                            v-for="option in filterOptions"
                            :key="option.value"
                            :aria-pressed="filter === option.value"
                            class="min-h-9 rounded-full border px-3 text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                            :class="
                                filter === option.value
                                    ? 'border-primary bg-primary text-on-primary'
                                    : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400'
                            "
                            :data-test="`library-filter-${option.value}`"
                            type="button"
                            @click="filter = option.value"
                        >
                            {{ $t(option.text) }}
                        </button>
                    </div>
                </div>

                <p v-if="loading" class="m-0 text-sm text-slate-500" data-test="library-loading">
                    {{ $t('text.widget_library.loading') }}
                </p>
                <div
                    v-else-if="failed"
                    class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-slate-300 p-6 text-center"
                    data-test="library-failed"
                >
                    <p class="m-0 text-sm text-slate-600">{{ $t('text.widget_library.load_failed') }}</p>
                    <i-button native-type="button" @click="load">{{ $t('text.widget_library.retry') }}</i-button>
                </div>
                <p
                    v-else-if="widgets.length === 0"
                    class="m-0 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500"
                    data-test="library-empty"
                >
                    {{ $t('text.widget_library.empty') }}
                </p>
                <p v-else-if="shown.length === 0" class="m-0 text-sm text-slate-500" data-test="library-no-match">
                    {{ $t('text.widget_library.no_match') }}
                </p>
                <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-test="library-grid">
                    <library-card
                        v-for="widget in shown"
                        :key="widget.id"
                        :can-block="canBlock"
                        :can-edit="canEdit"
                        :can-hub="canHub"
                        :has-update="updateVersion(widget) > 0"
                        :unexported="unexportedKeys.has(widget.widget_key)"
                        :update-version="updateVersion(widget)"
                        :widget="widget"
                        @add="openAdd(widget)"
                        @block="openBlock(widget)"
                        @edit="editDesign(widget)"
                        @preview="openPreview(widget)"
                        @update="openUpdate(widget)"
                        @usage="openUsage(widget)"
                    />
                </div>
            </template>
        </div>

        <a-modal
            v-model:open="previewOpen"
            :footer="null"
            :title="$t('text.widget_library.preview_title', { name: widgetLabel(active) })"
            width="min(1100px, calc(100vw - 32px))"
        >
            <div v-if="previewDesigns.length > 1" class="mb-3">
                <label class="mb-1 block text-sm font-medium text-slate-700" for="widget-preview-design">
                    {{ $t('text.widget_library.add_design') }}
                </label>
                <a-select
                    id="widget-preview-design"
                    v-model:value="previewThemeId"
                    class="w-full sm:w-64"
                    :options="previewDesigns.map((design) => ({ value: design.id, label: designLabel(design) }))"
                />
            </div>
            <p v-if="previewState === 'loading'" class="m-0 text-sm text-slate-500">
                {{ $t('text.widget_library.loading') }}
            </p>
            <p
                v-else-if="previewState === 'empty'"
                class="m-0 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500"
                data-test="preview-empty"
            >
                {{ $t('text.widget_library.preview_empty') }}
            </p>
            <a-alert
                v-else-if="previewState !== 'ready'"
                data-test="preview-message"
                :message="$t(previewState === 'blocked' ? 'text.widget_library.blocked_preview' : 'text.widget_library.preview_failed')"
                show-icon
                :type="previewState === 'blocked' ? 'warning' : 'error'"
            />
            <iframe
                v-else
                class="h-[70vh] w-full rounded border border-slate-200 bg-white"
                data-test="preview-frame"
                referrerpolicy="no-referrer"
                sandbox="allow-scripts"
                :srcdoc="previewHtml"
                :title="$t('text.widget_library.preview_title', { name: widgetLabel(active) })"
            />
        </a-modal>

        <update-dialog
            v-model:open="updateOpen"
            :hub-entry="active ? hubEntryOf(active) : null"
            :to-version="active ? updateVersion(active) : 0"
            :widget="active"
            @done="hubChanged($event, 'text.widget_library.hub_updated')"
        />
        <block-dialog v-model:open="blockOpen" :widget="active" @done="applyBlock" />
        <add-to-dialog v-model:open="addOpen" :show-area="showArea" :widget="active" />
        <usage-drawer v-model:open="usageOpen" :show-area="showArea" :widget="active" />
    </template>
    <template v-else>
        <no-permission-error />
    </template>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import { rule } from '@/mixins'
import { isModuleLiveEnabled } from '@/hooks/useActiveModules'
import { designLabel, widgetLabel } from '@/libs/widget-names'
import {
    forgetWidgetThumbnails,
    getCatalogStatus,
    getHubCatalog,
    getSharedAreaCount,
    hubErrorCopy,
    isBlankRender,
    listLibraryWidgets,
    onDesignPreviewUploaded,
    loadWidgetThumbnail,
    usableDesigns,
    widgetErrorCode,
} from '../../widget-library-api'
import LibraryCard from './components/library-card.vue'
import BlockDialog from './components/block-dialog.vue'
import AddToDialog from './components/add-to-dialog.vue'
import UsageDrawer from './components/usage-drawer.vue'
import HubCard from './components/hub-card.vue'
import UpdateDialog from './components/update-dialog.vue'
import UpdateBatchDialog from '../../components/update-batch/update-batch-dialog.vue'
import UpdateBatchPanel from '../../components/update-batch/update-batch-panel.vue'
import { isOpen as batchIsOpen } from '../../update-batch-api'
import { isWebmaster } from '@/hooks/useCan'

defineOptions({ name: 'WidgetLibrary' })

const router = useRouter()
const { t } = useI18n()

const canEdit = computed(() => rule('widget.update'))
const canBlock = computed(() => rule('widget.block'))
// Download and update from the hub; without it the screen is the site list alone.
const canHub = computed(() => rule('widget.hub'))

const widgets = ref([])
const loading = ref(true)
const failed = ref(false)
const unexportedKeys = ref(new Set())
// The local authoring copy publishes hub widgets and never takes hub updates (the server refuses them too).
const authoring = ref(false)
const areaCount = ref(0)

// Shared-area actions need the Widget module and a theme that has shared areas. The module state must come from the
// server in this page load: the copy kept in the browser can still say "on" after the module was turned off, and
// asking the Widget module for its areas would then answer 404.
const widgetModuleOn = computed(() => isModuleLiveEnabled('Widget'))
const showArea = computed(() => widgetModuleOn.value && areaCount.value > 0)

/** Read the site list. `quiet` keeps the cards on screen meanwhile and keeps them if the read fails. */
const load = async ({ quiet = false } = {}) => {
    if (!quiet) {
        loading.value = true
        failed.value = false
    }
    try {
        widgets.value = await listLibraryWidgets()
    } catch {
        if (!quiet) failed.value = true
    } finally {
        loading.value = false
    }
}

// 'site' | 'hub'. Without `widget.hub` there is no tab bar and the screen stays on the site list.
const tab = ref('site')

// Hub list: 'idle' | 'loading' | 'ready' | 'failed'. It is read apart from the site list, so a slow or unreachable hub
// never delays or breaks "Trên site".
const hubEntries = ref([])
const hubState = ref('idle')
const hubErrorKey = ref('')
let hubRequest = 0
// Downloads refused for good, widget key => translation key of the reason. Kept across list re-reads so the card
// stays explained and its button stays off; "Tải mới" starts over.
const hubRefusals = ref({})

/** Read the hub list; `refresh` asks the hub now ("Tải mới"), `quiet` keeps the cards on screen meanwhile. */
const loadHub = async ({ refresh = false, quiet = false } = {}) => {
    if (!canHub.value) return
    const request = ++hubRequest
    if (!quiet) hubState.value = 'loading'
    if (refresh) hubRefusals.value = {}
    try {
        const entries = await getHubCatalog(refresh ? { refresh: true } : undefined)
        if (request !== hubRequest) return
        hubEntries.value = entries
        hubState.value = 'ready'
    } catch (error) {
        if (request !== hubRequest) return
        hubEntries.value = []
        hubErrorKey.value = hubErrorCopy(error)
        hubState.value = 'failed'
    }
}

// "Trên hub" offers what the site does not have yet.
const hubAvailable = computed(() => hubEntries.value.filter((entry) => entry.installed_widget_id == null))
// Newest hub version per widget of this site, for rows the site list sent without it (hub list not kept yet).
const hubLatestById = computed(
    () =>
        new Map(
            hubEntries.value
                .filter((entry) => entry.installed_widget_id != null)
                .map((entry) => [Number(entry.installed_widget_id), Number(entry.latest_version)])
        )
)

/** Hub list entry of a widget of this site (its pictures and notes of the new version); null when not read. */
const hubEntryOf = (widget) =>
    hubEntries.value.find((entry) => entry.installed_widget_id != null && Number(entry.installed_widget_id) === Number(widget.id)) ?? null

/**
 * The newer hub version of a downloaded widget, 0 when there is none. A site copy never takes hub updates, and neither
 * does the authoring copy.
 */
const updateVersion = (widget) => {
    if (widget.origin !== 'hub' || authoring.value) return 0
    const latest = Number(widget.hub_latest_version ?? hubLatestById.value.get(Number(widget.id)) ?? 0)
    return latest > Number(widget.version ?? 0) ? latest : 0
}

// ---- Upgrade all ("Cập nhật tất cả", Webmaster only) -----------------------------------------------------------

const batchAllowed = computed(() => canHub.value && isWebmaster())
const batchPanel = ref(null)
const batchDialogOpen = ref(false)
const batchState = ref(null)
const batchOpen = computed(() => batchIsOpen(batchState.value))
/** Hub widgets of this site with a newer version. */
const widgetUpdates = computed(() => widgets.value.filter((widget) => updateVersion(widget) > 0).length)

/** A run that just ended changed widgets: read the list and the hub again. */
const onBatchState = (next) => {
    const wasOpen = batchOpen.value
    batchState.value = next
    if (wasOpen && !batchIsOpen(next)) {
        load({ quiet: true })
        loadHub({ quiet: true })
    }
}

const onBatchStarted = () => {
    batchDialogOpen.value = false
    batchPanel.value?.load?.()
}

/**
 * After "Tải về" or "Cập nhật": say it is done, or that the widget is saved while its files are still being prepared
 * (the plain "done" would be misleading then), and read both lists again.
 */
const hubChanged = (result, doneKey) => {
    if (result?.sync_status === 'pending') {
        message.warning(t('text.widget_library.hub_sync_pending'))
    } else {
        message.success(t(doneKey))
    }
    if (result?.id) forgetWidgetThumbnails(result.id)
    load({ quiet: true })
    loadHub({ quiet: true })
}

const loadCatalogStatus = async () => {
    const status = await getCatalogStatus()
    authoring.value = status.authoring === true
    unexportedKeys.value = new Set(status.authoring ? status.unexported_widget_keys : [])
}

// The module list arrives after the first paint, so the area count is read once the module is known to be on.
watch(
    widgetModuleOn,
    async (on) => {
        areaCount.value = on ? await getSharedAreaCount() : 0
    },
    { immediate: true }
)

// A design picture captured after a save in the design editor arrives after this list was read: put it on the card.
const stopPreviewListener = onDesignPreviewUploaded((saved) => {
    widgets.value = widgets.value.map((widget) =>
        (widget.template ?? []).some((design) => design.id === saved.id)
            ? {
                  ...widget,
                  template: widget.template.map((design) =>
                      design.id === saved.id
                          ? { ...design, preview_image_url: saved.preview_image_url, preview_stale: saved.preview_stale === true }
                          : design
                  ),
              }
            : widget
    )
})
onBeforeUnmount(stopPreviewListener)

onMounted(() => {
    load()
    loadCatalogStatus()
    loadHub()
})

const search = ref('')
const filter = ref('all')
const filterOptions = [
    { value: 'all', text: 'text.widget_library.filter_all' },
    { value: 'hub', text: 'text.widget_library.origin_hub' },
    { value: 'site', text: 'text.widget_library.origin_site' },
    { value: 'blocked', text: 'text.widget_library.blocked' },
]

const shown = computed(() => {
    const term = search.value.trim().toLowerCase()
    return widgets.value.filter((widget) => {
        if (filter.value === 'blocked' && !widget.is_blocked) return false
        if (filter.value === 'hub' && widget.origin !== 'hub') return false
        if (filter.value === 'site' && widget.origin === 'hub') return false
        // Found by the name on the card and by the original name.
        return !term || `${widgetLabel(widget)} ${widget.name ?? ''}`.toLowerCase().includes(term)
    })
})

// The widget the open dialog or drawer is about.
const active = ref(null)
const blockOpen = ref(false)
const addOpen = ref(false)
const usageOpen = ref(false)
const previewOpen = ref(false)
const updateOpen = ref(false)

const openBlock = (widget) => {
    active.value = widget
    blockOpen.value = true
}
const openAdd = (widget) => {
    active.value = widget
    addOpen.value = true
}
const openUpdate = (widget) => {
    active.value = widget
    updateOpen.value = true
}
const openUsage = (widget) => {
    active.value = widget
    usageOpen.value = true
}

/** After a block or unblock: update the card in place and drop its cached previews. */
const applyBlock = ({ id, is_blocked, blocked_reason }) => {
    forgetWidgetThumbnails(id)
    widgets.value = widgets.value.map((widget) => (widget.id === id ? { ...widget, is_blocked, blocked_reason } : widget))
}

/** "Sửa thiết kế": the design editor opens this widget directly. */
const editDesign = (widget) => router.push({ name: 'widget-library-sources', query: { edit: widget.id } })

// Preview dialog: 'loading' | 'ready' | 'empty' (the design renders nothing with sample content) | 'blocked' | 'failed'.
const previewState = ref('loading')
const previewHtml = ref('')
const previewThemeId = ref(null)
// Only designs this site can use: a design of another project renders nothing here.
const previewDesigns = computed(() => usableDesigns(active.value))

const openPreview = (widget) => {
    active.value = widget
    previewThemeId.value = usableDesigns(widget)[0]?.id ?? null
    previewOpen.value = true
}

watch([previewOpen, previewThemeId, () => active.value?.is_blocked], async ([open, themeId, blocked]) => {
    if (!open || !active.value || !themeId) return
    if (blocked) {
        previewState.value = 'blocked'
        return
    }
    previewState.value = 'loading'
    const widgetId = active.value.id
    try {
        const html = await loadWidgetThumbnail(widgetId, themeId, active.value.version)
        if (active.value?.id !== widgetId || previewThemeId.value !== themeId) return
        previewHtml.value = html
        previewState.value = isBlankRender(html) ? 'empty' : 'ready'
    } catch (error) {
        // A late failure of an earlier widget or design must not replace what is shown now.
        if (active.value?.id !== widgetId || previewThemeId.value !== themeId) return
        previewState.value = widgetErrorCode(error) === 'WIDGET_BLOCKED' ? 'blocked' : 'failed'
    }
})
</script>
