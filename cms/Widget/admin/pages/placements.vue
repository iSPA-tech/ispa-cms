<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    "Khu vực chung" — the editor of the parts of the site that repeat on many pages (header, footer and the other
    shared areas the active theme declares). Built for a site owner, not a developer: nothing on screen names a key, an
    id or a version.

    WHAT THE USER SEES
      - The page opens on a live preview of the HOME PAGE with the current draft applied. Every shared area the page
        has is outlined in place with its name and its own "+ Thêm"; a placed widget is clickable in place.
      - A slim list on the left mirrors the areas with their widget counts. It is the full-keyboard / small-screen path
        to everything the preview offers, and says plainly when an area is not on the page being viewed (page-only
        areas appear after picking a page in the command bar).
      - "+ Thêm" opens ONE add drawer (choose → content → where it shows). Clicking a widget opens its editor in a wide
        drawer with "Lưu", "Nhân bản" and "Gỡ khỏi khu vực".
      - The draft saves itself. The command bar shows "● N thay đổi chưa xuất bản"; "Xuất bản…" lists the changes in
        plain words before anything reaches the site; "Lịch sử" restores an earlier published version.
      - A theme without shared areas gets one sentence instead of an empty tool.

    HOW IT SAVES
      - Adding, editing content and duplicating go through the placement routes of the shared layout composable
        (each saves immediately and answers the whole draft). The `/instances` routes are never called.
      - Arrangement changes (order, where it shows, removing) edit the local draft and are saved by an autosave
        (PUT draft) shortly after the last change; a placement request flushes a pending autosave first.
      - When someone else changed the draft meanwhile, the draft is read again and the user is told to check — never a
        raw server message, never a silent retry.

    The widget library hand-off (`?add_widget=<id>&theme=<id>`) is read once: it opens the add drawer on the content
    step for the first area and is then removed from the address.
-->
<template>
    <div class="mx-auto max-w-[1760px] space-y-4">
        <breadcrumbs
            :items="[{ icon: 'view-dashboard-outline', text: $t('nav.a.site-layout'), title: $t('nav.a.site-layout') }]"
        >
            <template #description>{{ $t('widget_site_layout.area.intro') }}</template>
        </breadcrumbs>

        <!-- Outcomes without a visible live region of their own (save, publish, restore, add, remove). -->
        <p aria-atomic="true" aria-live="polite" class="sr-only" data-test="page-announcer">{{ announcement }}</p>

        <p
            v-if="noAreas"
            class="m-0 rounded-xl border border-slate-200 bg-white px-6 py-10 text-center text-sm text-slate-600"
            data-test="no-areas-state"
        >
            {{ $t('widget_site_layout.area.empty') }}
        </p>

        <p
            v-else-if="slotsFailed"
            class="m-0 rounded-xl border border-amber-200 bg-amber-50 px-6 py-10 text-center text-sm text-amber-900"
            data-test="areas-failed-state"
            role="alert"
        >
            {{ $t('widget_site_layout.area.load_failed') }}
        </p>

        <template v-else>
            <!-- Widget library hand-off with several areas: the user says where the chosen widget goes. -->
            <div
                v-if="pendingPreset"
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-2.5 text-sm text-cyan-900"
                data-test="hand-off-notice"
                role="status"
            >
                <span class="min-w-0">{{ $t('widget_site_layout.area.hand_off_pick_area') }}</span>
                <button
                    class="rounded-lg border border-cyan-300 bg-white px-3 py-1.5 text-sm text-cyan-800 hover:bg-cyan-100"
                    data-test="hand-off-cancel"
                    type="button"
                    @click="pendingPreset = null"
                >
                    {{ $t('nav.b.cancel') }}
                </button>
            </div>

            <p
                v-if="!canEdit"
                class="m-0 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700"
                data-test="read-only-notice"
                role="status"
            >
                {{ $t('widget_site_layout.area.read_only') }}
            </p>

            <!-- Command bar. The app header is in normal flow, so top-0 sticks only after it scrolled away. -->
            <div class="sticky top-0 z-20 bg-slate-100/95 py-2 backdrop-blur" data-test="editor-sticky-bar">
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 shadow-sm">
                    <widget-layout-save-bar
                        flat
                        :layout="layout"
                        @open-history="historyOpen = true"
                        @publish="publishOpen = true"
                    >
                        <template #context>
                            <site-layout-context-toolbar
                                v-model="previewContext"
                                :home-label="$t('widget_site_layout.area.preview_home')"
                                plain
                                :target-contexts="targetContexts"
                            />
                        </template>
                    </widget-layout-save-bar>
                </div>
            </div>

            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[300px_minmax(0,1fr)]" data-test="site-layout-workspace">
                <aside
                    :aria-label="$t('widget_site_layout.area.areas_list')"
                    class="min-w-0 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto"
                    data-test="left-rail"
                >
                    <site-layout-structure-tree
                        :can-edit="canEdit"
                        :content-for="layout.contentFor"
                        flat
                        :placements="layout.state.localDraft?.placements ?? []"
                        :selected-key="selectedPlacementKey"
                        :slots="slots"
                        :visible-areas="canPreview ? visibleAreas : null"
                        @add-to-slot="openAdd"
                        @focus-area="focusArea"
                        @move="movePlacementInArea"
                        @select="handleSelect"
                    />
                </aside>

                <section
                    :aria-label="$t('widget_site_layout.action.preview')"
                    class="min-w-0"
                    data-test="canvas-pane"
                    role="region"
                >
                    <widget-layout-preview
                        v-if="canPreview"
                        ref="previewRef"
                        :add-label="canEdit ? $t('widget_site_layout.area.add') : ''"
                        :area-labels="canEdit ? areaLabels : {}"
                        :context="previewContext"
                        :editor-mode="true"
                        :is-draft-dirty="isDraftDirty"
                        :layout-uuid="layout.state.layoutUuid"
                        :placements="layout.state.baselineDraft?.placements ?? []"
                        :selected-key="selectedPlacementKey"
                        @add="openAdd"
                        @areas="visibleAreas = $event"
                        @select="handlePreviewSelect"
                    />
                    <p
                        v-else
                        class="m-0 rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-600"
                        data-test="no-preview-notice"
                    >
                        {{ $t('widget_site_layout.area.no_preview') }}
                    </p>
                </section>
            </div>
        </template>

        <area-add-drawer
            :layout="layout"
            :open="addOpen"
            :preset="addPreset"
            :slot="addSlot"
            :target-contexts="targetContexts"
            @added="onAdded"
            @close="addOpen = false"
        />

        <!-- Editor of one placed widget: 560px from 768px up, the full width below. -->
        <widget-layout-drawer
            :open="!!selectedPlacement"
            side="right"
            :title="editTitle"
            wide
            @close="closeEdit"
        >
            <div v-if="selectedPlacement" class="space-y-4" data-test="area-edit-drawer">
                <p
                    v-if="editError"
                    class="m-0 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                    data-test="area-edit-error"
                    role="alert"
                >
                    {{ editError }}
                </p>
                <widget-placement-inspector
                    :key="selectedPlacementKey"
                    :content="selectedContent"
                    :scope="editScope"
                    :scope-types="selectedScopeTypes"
                    :show-scope="canEdit"
                    :target-contexts="targetContexts"
                    @update:scope="editScope = $event"
                    @update:values="editValues = $event"
                />
            </div>

            <template v-if="canEdit" #footer>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50"
                        data-test="area-edit-save"
                        :disabled="editBusy || !editScopeComplete"
                        type="button"
                        @click="saveEdit"
                    >
                        {{ $t('widget_site_layout.area.save') }}
                    </button>
                    <button
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        data-test="area-edit-duplicate"
                        :disabled="editBusy"
                        type="button"
                        @click="duplicateSelected"
                    >
                        {{ $t('widget_site_layout.area.duplicate') }}
                    </button>
                    <button
                        class="ml-auto rounded-lg border border-rose-300 px-3 py-2 text-sm text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                        data-test="area-edit-remove"
                        :disabled="editBusy"
                        type="button"
                        @click="removeSelected"
                    >
                        {{ $t('widget_site_layout.area.remove') }}
                    </button>
                </div>
            </template>
        </widget-layout-drawer>

        <area-publish-dialog :layout="layout" :open="publishOpen" @close="publishOpen = false" @published="onPublished" />

        <widget-layout-release-drawer
            ref="releaseDrawerRef"
            :layout="layout"
            :open="historyOpen"
            @close="historyOpen = false"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useWidgetSiteLayout } from '../use-widget-site-layout'
import * as api from '../api'
import { iWidgetError } from '../widget-error-toast'
import { groupKey, movePlacement, normalizePositions, removePlacement } from '../placement-canvas-utils'
import { resolveSlotLabel, sortSlotsByPageOrder } from '../slot-label'
import { isScopeComplete, widgetDisplayName } from '../area-format'
import { setAreaCount } from '../area-count'
import { defaultLocale } from '@/config'
import AreaAddDrawer from '../components/area-add-drawer.vue'
import AreaPublishDialog from '../components/area-publish-dialog.vue'
import WidgetPlacementInspector from '../components/widget-placement-inspector.vue'
import SiteLayoutContextToolbar from '../components/site-layout-context-toolbar.vue'
import SiteLayoutStructureTree from '../components/site-layout-structure-tree.vue'
import WidgetLayoutDrawer from '../components/widget-layout-drawer.vue'
import WidgetLayoutPreview from '../components/widget-layout-preview.vue'
import WidgetLayoutSaveBar from '../components/widget-layout-save-bar.vue'
import WidgetLayoutReleaseDrawer from '../components/widget-layout-release-drawer.vue'
import { can, iAlert } from '@/mixins'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const layout = useWidgetSiteLayout()

const slots = ref([])
const slotsLoaded = ref(false)
const slotsFailed = ref(false)
const targetContexts = ref([])

const canEdit = can('widget_layout.update')
const canPreview = can('widget_layout.preview')
const isDraftDirty = computed(() => layout.hasUnsavedChanges())

// One sentence instead of an empty tool when the active theme declares no shared area.
const noAreas = computed(() => slotsLoaded.value && !slotsFailed.value && slots.value.length === 0)

/** Area names by area key, drawn on the preview and used in messages. */
const areaLabels = computed(() => Object.fromEntries(slots.value.map((slot) => [slot.key, resolveSlotLabel(t, slot)])))

// ---------------------------------------------------------------------------
// Page being viewed. Always opens on the home page (no page picked): language
// and screen size are remembered between visits, the picked page is not.
// ---------------------------------------------------------------------------

const CONTEXT_STORAGE_KEY = 'wpe-site-layout-editor-context.v2'
const SCOPE_ORDER = ['global', 'template', 'target']

function restoreStoredContext() {
    try {
        const parsed = JSON.parse(window.localStorage?.getItem(CONTEXT_STORAGE_KEY) ?? 'null')
        return typeof parsed === 'object' && parsed !== null ? parsed : null
    } catch {
        return null
    }
}

const storedContext = restoreStoredContext()

const previewContext = ref({
    targetType: null,
    targetId: null,
    targetLabel: null,
    templateContext: null,
    locale: storedContext?.locale ?? defaultLocale,
    viewport: storedContext?.viewport ?? 'desktop',
})

watch(
    previewContext,
    (context) => {
        try {
            window.localStorage?.setItem(
                CONTEXT_STORAGE_KEY,
                JSON.stringify({ locale: context?.locale ?? defaultLocale, viewport: context?.viewport ?? 'desktop' })
            )
        } catch {
            // Storage unavailable (private mode, quota): remembering is a convenience only.
        }
    },
    { deep: true }
)

/** Areas the page in the preview has (reported by the preview); null while unknown. */
const visibleAreas = ref(null)
const previewRef = ref(null)

function focusArea(slotKey) {
    previewRef.value?.focusArea(slotKey)
}

// ---------------------------------------------------------------------------
// Selection: one identity (area + where it shows + the placement's content
// owner), resolved against the live draft so it survives a draft reload.
// ---------------------------------------------------------------------------

const selectedPlacementKey = ref(null)
const keyOf = (placement) => `${groupKey(placement)}::${placement.widget_instance_uuid}`
const draftPlacements = () => layout.state.localDraft?.placements ?? []

const selectedPlacement = computed(() => {
    if (!selectedPlacementKey.value) return null
    return draftPlacements().find((placement) => keyOf(placement) === selectedPlacementKey.value) ?? null
})
const selectedContent = computed(() => layout.contentFor(selectedPlacement.value))
// Same order as the add drawer: "Toàn website", "Theo loại trang", "Riêng trang này".
const selectedScopeTypes = computed(() => {
    const supported = slots.value.find((slot) => slot.key === selectedPlacement.value?.slot_key)?.supported_scope_types ?? ['global']
    return [...supported].sort((a, b) => SCOPE_ORDER.indexOf(a) - SCOPE_ORDER.indexOf(b))
})
const editTitle = computed(() =>
    t('widget_site_layout.area.edit_title', { name: widgetDisplayName(t, selectedContent.value) })
)

/** Content typed in the open editor; null until the user changes a field (an untouched form saves no new content). */
const editValues = ref(null)
const editScope = ref({ scope_type: 'global', scope_key: null, target_type: null, target_id: null })
const editBusy = ref(false)
const editError = ref('')
const editScopeComplete = computed(() => isScopeComplete(editScope.value))

const scopeOf = (placement) => ({
    scope_type: placement?.scope_type ?? 'global',
    scope_key: placement?.scope_key ?? null,
    target_type: placement?.target_type ?? null,
    target_id: placement?.target_id ?? null,
})
const sameScope = (a, b) => JSON.stringify(scopeOf(a)) === JSON.stringify(scopeOf(b))

function handleSelect(placement) {
    selectedPlacementKey.value = keyOf(placement)
    editValues.value = null
    editScope.value = scopeOf(placement)
    editError.value = ''
}

function closeEdit() {
    if (editBusy.value) return
    selectedPlacementKey.value = null
}

/** A click on a widget inside the preview. The preview shows the saved draft, so a widget just moved or removed
 * locally may no longer match: say so instead of ignoring the click. */
function handlePreviewSelect({ key }) {
    const match = draftPlacements().find((placement) => keyOf(placement) === key) ?? null
    if (match) handleSelect(match)
    else announce(t('widget_site_layout.editor.local_only_note'))
}

/** Plain sentence for a refused placement request (never the server's message). */
function failureText(result) {
    if (result.reason === 'blocked') return t('widget_site_layout.area.blocked_error')
    if (result.reason === 'stale') return t('widget_site_layout.area.stale_reloaded')
    if (result.reason === 'reloaded') return t('widget_site_layout.area.reloaded_check')
    return t('widget_site_layout.area.save_failed')
}

/** After the draft was read again the open widget may be gone or changed: close its editor and tell the user. */
function leaveEditorAfterReload(result) {
    selectedPlacementKey.value = null
    iAlert(failureText(result), true)
}

async function saveEdit() {
    if (editBusy.value || !selectedPlacement.value || !editScopeComplete.value) return
    editBusy.value = true
    editError.value = ''

    try {
        let key = selectedPlacementKey.value

        // 1. Where it shows: an arrangement change, saved with the draft.
        if (!sameScope(selectedPlacement.value, editScope.value)) {
            const index = draftPlacements().findIndex((placement) => keyOf(placement) === key)
            const next = normalizePositions(
                draftPlacements().map((placement, i) => (i === index ? { ...placement, ...scopeOf(editScope.value) } : placement))
            )
            layout.updateLocalDraft(next)
            key = keyOf(next[index])
            selectedPlacementKey.value = key
            if (!(await layout.flushDraft())) return // the command bar explains the failed save or the conflict
        }

        // 2. Content: only when a field was changed.
        if (editValues.value !== null) {
            const saved = (layout.state.baselineDraft?.placements ?? []).find((placement) => keyOf(placement) === key)
            if (!saved) return
            const result = await layout.updatePlacementContent(saved.uuid, { values: editValues.value })
            if (!result.ok) {
                if (result.reason === 'stale' || result.reason === 'reloaded') leaveEditorAfterReload(result)
                else editError.value = failureText(result)
                return
            }
        }

        selectedPlacementKey.value = null
        iAlert(t('widget_site_layout.area.saved'))
        announce(t('widget_site_layout.area.saved'))
    } finally {
        editBusy.value = false
    }
}

async function duplicateSelected() {
    if (editBusy.value || !selectedPlacement.value) return
    editBusy.value = true
    editError.value = ''

    try {
        if (!(await layout.flushDraft())) return // the command bar explains
        const saved = (layout.state.baselineDraft?.placements ?? []).find(
            (placement) => keyOf(placement) === selectedPlacementKey.value
        )
        if (!saved) return
        const result = await layout.duplicatePlacement(saved.uuid)
        if (!result.ok) {
            if (result.reason === 'stale' || result.reason === 'reloaded') leaveEditorAfterReload(result)
            else editError.value = failureText(result)
            return
        }

        selectedPlacementKey.value = null
        iAlert(t('widget_site_layout.area.duplicated'))
        announce(t('widget_site_layout.area.duplicated'))
    } finally {
        editBusy.value = false
    }
}

/** Removing = the draft saved without the placement (autosaved below); its content is cleaned up on publish. */
function removeSelected() {
    if (editBusy.value || !selectedPlacement.value) return
    const { placements } = removePlacement(draftPlacements(), selectedPlacement.value)
    layout.updateLocalDraft(placements)
    selectedPlacementKey.value = null
    announce(t('widget_site_layout.area.removed'))
}

function movePlacementInArea(placement, direction) {
    layout.updateLocalDraft(movePlacement(draftPlacements(), placement, direction))
}

// ---------------------------------------------------------------------------
// Autosave of arrangement changes: one save shortly after the last change.
// A failed save is not repeated on its own — the command bar offers a retry;
// a conflict waits for the user's choice there.
// ---------------------------------------------------------------------------

const AUTOSAVE_DELAY_MS = 800
let autosaveTimer = null

watch(
    () => [isDraftDirty.value, layout.state.localDraft?.placements, layout.state.status],
    ([dirty]) => {
        clearTimeout(autosaveTimer)
        if (!dirty || !canEdit) return
        autosaveTimer = setTimeout(() => {
            if (layout.state.status === 'ready' && layout.hasUnsavedChanges()) layout.saveDraft()
        }, AUTOSAVE_DELAY_MS)
    }
)

// ---------------------------------------------------------------------------
// Add drawer
// ---------------------------------------------------------------------------

const addOpen = ref(false)
const addSlot = ref(null)
const addPreset = ref(null)
/** Widget handed over by the widget library, waiting for the user to choose its area. */
const pendingPreset = ref(null)

function openAdd(slotKey) {
    const slot = slots.value.find((item) => item.key === slotKey)
    if (!slot || !canEdit) return
    addSlot.value = slot
    addPreset.value = pendingPreset.value
    pendingPreset.value = null
    selectedPlacementKey.value = null
    addOpen.value = true
}

function onAdded({ areaLabel }) {
    addOpen.value = false
    const message = t('widget_site_layout.area.added', { area: areaLabel })
    iAlert(message)
    announce(message)
}

/** Widget library hand-off: read `?add_widget=&theme=` once, remove it from the address, open the add drawer. */
function takeLibraryHandOff() {
    const query = route?.query ?? {}
    const widgetId = Number(query.add_widget ?? 0)
    if (!widgetId) return null
    const { add_widget: _widget, theme, ...rest } = query
    router.replace({ query: rest })
    return { widgetId, themeId: Number(theme ?? 0) || null }
}

// ---------------------------------------------------------------------------
// Publish + history
// ---------------------------------------------------------------------------

const publishOpen = ref(false)
const historyOpen = ref(false)
const releaseDrawerRef = ref(null)

function onPublished() {
    publishOpen.value = false
    iAlert(t('widget_site_layout.area.published'))
    releaseDrawerRef.value?.loadReleases()
}

// ---------------------------------------------------------------------------
// Announcements for screen readers, from the shared composable's own status.
// ---------------------------------------------------------------------------

const announcement = ref('')
function announce(message) {
    announcement.value = message
}

watch(
    () => layout.state.status,
    (next, prev) => {
        if (next === 'ready' && prev === 'saving') announce(t('text.widget.announce_saved'))
        else if (next === 'ready' && prev === 'publishing') announce(t('text.widget.announce_published'))
        else if (next === 'ready' && prev === 'rolling_back') announce(t('text.widget.announce_rolled_back'))
        else if (next === 'conflict') announce(t('text.widget.announce_conflict'))
    }
)

onMounted(async () => {
    const handOff = takeLibraryHandOff()

    try {
        const result = await api.listSlotsWithAreaCount()
        slots.value = sortSlotsByPageOrder(result.slots)
        setAreaCount(result.areaCount)
    } catch (error) {
        slotsFailed.value = true
        iWidgetError(error)
    } finally {
        slotsLoaded.value = true
    }

    if (noAreas.value || slotsFailed.value) return

    // A role without `widget_layout.update` only reads the layout: the creating PUT would be refused on every load.
    await layout.bootstrap('site', { readOnly: !canEdit })

    try {
        targetContexts.value = (await api.listTargetContexts()) ?? []
    } catch {
        // The page and page-type pickers simply stay empty.
        targetContexts.value = []
    }

    if (handOff && canEdit) {
        pendingPreset.value = handOff
        // One area: nothing to choose, go straight to the widget's content.
        if (slots.value.length === 1) openAdd(slots.value[0].key)
    }
})

onUnmounted(() => {
    clearTimeout(autosaveTimer)
    layout.dispose()
})
</script>
