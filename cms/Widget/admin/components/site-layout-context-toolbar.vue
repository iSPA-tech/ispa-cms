<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Redesign (IA pass): the preview-context controls, now a COMPACT control
    cluster meant to live inside the placements command bar instead of a
    standalone card. Three primary-layer controls only -- preview page /
    locale / viewport -- because that is the admin's mental model; the
    technical context (derived Template Context key, project applicability)
    moved into a small "context details" disclosure popover, no longer a
    permanent line of jargon under the page field.

    The page control is a CHIP + popover: after a page is picked the chip
    shows the selected label with a "change page" affordance, instead of a
    free-text input that LOOKS editable but does not actually re-target until
    a listbox row is chosen. The popover hosts the same widget-target-picker
    combobox (search/keyboard/cancellation contract unchanged).

    Derivation rules unchanged from Task 29: Template Context always comes
    from the SAME WidgetTargetContextResource row the chosen target_type
    belongs to (`template_context_key`) -- backend mint validation rejects
    anything else -- and "project" is never a client-selectable value; only
    the read-only `project_applicable` flag is surfaced (now inside the
    details popover).

    Reference data: the host page fetches listTargetContexts() ONCE and
    passes it via `targetContexts` (also forwarded into the embedded
    widget-target-picker), removing the double-fetch this component and the
    picker used to perform independently.
-->
<template>
    <div class="flex flex-wrap items-center gap-2" data-test="context-toolbar">
        <!-- Preview page chip + popover picker -->
        <div class="relative">
            <button
                :aria-expanded="pagePickerOpen ? 'true' : 'false'"
                class="flex max-w-full items-center gap-1.5 sm:max-w-[260px] rounded-lg border px-2.5 py-1.5 text-sm transition-colors"
                :class="
                    modelValue?.targetId
                        ? 'border-indigo-200 bg-indigo-50 text-indigo-800 hover:border-indigo-300'
                        : 'border-slate-300 text-slate-600 hover:bg-slate-50'
                "
                data-test="context-page-chip"
                type="button"
                @click="pagePickerOpen = !pagePickerOpen"
            >
                <i-icon class="size-4 shrink-0 text-slate-400" icon="web" />
                <span class="truncate">
                    {{ modelValue?.targetLabel ?? homeLabel ?? $t('widget_site_layout.editor.pick_preview_page') }}
                </span>
                <i-icon class="size-4 shrink-0" icon="chevron-down" />
            </button>

            <template v-if="pagePickerOpen">
                <div class="fixed inset-0 z-20" data-test="context-page-popover-backdrop" @click="pagePickerOpen = false"></div>
                <div
                    class="absolute left-0 z-30 mt-1 w-80 max-w-[calc(100vw-3rem)] rounded-xl border border-slate-200 bg-white p-3 shadow-lg"
                    data-test="context-page-popover"
                    @keydown.esc="pagePickerOpen = false"
                >
                    <p class="m-0 mb-1.5 text-xs font-medium uppercase text-slate-500">
                        {{ $t('widget_site_layout.editor.preview_page') }}
                    </p>
                    <widget-target-picker
                        :contexts="targetContexts"
                        :model-value="targetSelection"
                        @update:model-value="onTargetSelected"
                    />
                    <!-- Back to the default view (the home page): only offered while a page is picked. -->
                    <button
                        v-if="homeLabel && modelValue?.targetId"
                        class="mt-2 flex w-full items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                        data-test="context-page-home"
                        type="button"
                        @click="clearTarget"
                    >
                        <i-icon class="size-4 shrink-0 text-slate-400" icon="home-outline" />
                        {{ homeLabel }}
                    </button>
                </div>
            </template>
        </div>

        <!-- Locale -->
        <select
            v-model="locale"
            :aria-label="$t('nav.a.site-layout-locale')"
            class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm text-slate-700 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            data-test="context-locale"
            @change="emitChange"
        >
            <option v-for="code in supportLocales" :key="code" :value="code">{{ localeName(code) }}</option>
        </select>

        <!-- Viewport -->
        <div :aria-label="$t('nav.a.site-layout-viewport')" class="inline-flex rounded-lg border border-slate-300 p-0.5" role="group">
            <button
                :aria-pressed="viewport === 'desktop' ? 'true' : 'false'"
                :class="viewportButtonClass(viewport === 'desktop')"
                data-test="context-viewport-desktop"
                :title="$t('nav.a.site-layout-viewport-desktop')"
                type="button"
                @click="selectViewport('desktop')"
            >
                <i-icon class="size-4" icon="monitor" />
            </button>
            <button
                :aria-pressed="viewport === 'mobile' ? 'true' : 'false'"
                :class="viewportButtonClass(viewport === 'mobile')"
                data-test="context-viewport-mobile"
                :title="$t('nav.a.site-layout-viewport-mobile')"
                type="button"
                @click="selectViewport('mobile')"
            >
                <i-icon class="size-4" icon="cellphone" />
            </button>
        </div>

        <!-- Technical context details (Template Context / project applicability) -->
        <div v-if="!plain" class="relative">
            <button
                :aria-expanded="detailsOpen ? 'true' : 'false'"
                :aria-label="$t('widget_site_layout.editor.context_details')"
                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                data-test="context-details-toggle"
                type="button"
                @click="detailsOpen = !detailsOpen"
            >
                <i-icon class="size-4" icon="information-outline" />
            </button>

            <template v-if="detailsOpen">
                <div class="fixed inset-0 z-20" @click="detailsOpen = false"></div>
                <div
                    class="absolute right-0 z-30 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-lg"
                    data-test="context-details"
                    @keydown.esc="detailsOpen = false"
                >
                    <p class="m-0 font-medium uppercase text-slate-500">{{ $t('widget_site_layout.editor.context_details') }}</p>
                    <p class="m-0 mt-1.5 text-slate-600" data-test="context-template-context">
                        {{ $t('nav.a.site-layout-template-context') }}:
                        <span class="font-medium text-slate-800">{{ templateContextLabel ?? '—' }}</span>
                    </p>
                    <p
                        class="m-0 mt-1"
                        :class="projectApplicable ? 'text-indigo-700' : 'text-slate-500'"
                        data-test="context-project-badge"
                    >
                        {{
                            projectApplicable
                                ? $t('nav.a.site-layout-context-project-scoped')
                                : $t('nav.a.site-layout-context-project-global')
                        }}
                    </p>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { defaultLocale } from '@/config'
// `supportLocales` is not part of the `@/config` barrel's test mock (only
// `@/config/base`'s own mock defines it -- see src/__tests__/setup.ts), so
// it is imported from that specific module rather than the barrel, matching
// its real (also hardcoded ['en', 'vi']) source of truth in src/config/base.js.
import { supportLocales } from '@/config/base'
import WidgetTargetPicker from './widget-target-picker.vue'

defineOptions({ name: 'SiteLayoutContextToolbar' })

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
    /** Pre-fetched WidgetTargetContextResource rows (host page owns the ONE
     * listTargetContexts() call for the whole editor). */
    targetContexts: {
        type: Array,
        default: () => [],
    },
    /** Name shown on the page chip while no page is picked (the preview then shows the home page). */
    homeLabel: {
        type: String,
        default: null,
    },
    /** Hide the technical details popover (the area editor shows no internal terms). */
    plain: {
        type: Boolean,
        default: false,
    },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const pagePickerOpen = ref(false)
const detailsOpen = ref(false)

const contextsByType = computed(() => new Map(props.targetContexts.map((context) => [context.target_type, context])))

const targetSelection = reactive({
    target_type: props.modelValue.targetType ?? null,
    target_id: props.modelValue.targetId ?? null,
    label: props.modelValue.targetLabel ?? null,
})
const locale = ref(props.modelValue.locale ?? defaultLocale)
const viewport = ref(props.modelValue.viewport ?? 'desktop')

// The host page can also change the context from OUTSIDE this toolbar (the
// center empty state hosts the same picker) -- keep the chip/locale/viewport
// in sync instead of showing a stale selection.
watch(
    () => props.modelValue,
    (next) => {
        targetSelection.target_type = next?.targetType ?? null
        targetSelection.target_id = next?.targetId ?? null
        targetSelection.label = next?.targetLabel ?? null
        if (next?.locale) locale.value = next.locale
        if (next?.viewport) viewport.value = next.viewport
    },
    { deep: true }
)

const selectedContextDefinition = computed(() => contextsByType.value.get(targetSelection.target_type) ?? null)
const derivedTemplateContext = computed(() => selectedContextDefinition.value?.template_context_key ?? null)

/** Human label for the derived Template Context: resolves the pack's
 * widget_site_layout.template_context.<key with [.] -> _> entry, falling back
 * to the raw key (still meaningful, e.g. `page.static`) when untranslated. */
const templateContextLabel = computed(() => {
    const key = derivedTemplateContext.value
    if (!key) return null
    const packKey = `widget_site_layout.template_context.${String(key).replace(/[.:]+/g, '_')}`
    const translated = t(packKey)
    return translated === packKey ? key : translated
})
const projectApplicable = computed(() => selectedContextDefinition.value?.project_applicable ?? false)

/** Locale display name (translated), never the bare `en`/`vi` code. */
function localeName(code) {
    const key = `widget_site_layout.editor.locale_${code}`
    const translated = t(key)
    return translated === key ? code : translated
}

function emitChange() {
    emit('update:modelValue', {
        targetType: targetSelection.target_type,
        targetId: targetSelection.target_id,
        targetLabel: targetSelection.label,
        templateContext: derivedTemplateContext.value,
        locale: locale.value,
        viewport: viewport.value,
    })
}

function onTargetSelected(value) {
    targetSelection.target_type = value.target_type
    targetSelection.target_id = value.target_id
    targetSelection.label = value.label
    pagePickerOpen.value = false
    emitChange()
}

/** Drop the picked page: the preview goes back to the home page. */
function clearTarget() {
    targetSelection.target_type = null
    targetSelection.target_id = null
    targetSelection.label = null
    pagePickerOpen.value = false
    emitChange()
}

function selectViewport(next) {
    viewport.value = next
    emitChange()
}

const viewportButtonClass = (active) => [
    'rounded-md px-2 py-1',
    active ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50',
]
</script>
