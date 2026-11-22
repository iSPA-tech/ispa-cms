<template>
    <div ref="root" class="relative min-w-0" :aria-busy="busy ? 'true' : 'false'">
        <!-- Off-screen (not requested yet): a one-line placeholder, never an empty white frame. -->
        <div v-if="!started" class="p-4">
            <div class="flex min-h-11 items-center justify-center gap-2.5 rounded-md border border-dashed border-[#c5cbd3] bg-[#f6f8f9] px-3 text-[13px] text-[#5b6470]">
                <span class="size-2 shrink-0 rounded-full bg-[#17a2b8]" aria-hidden="true" />
                <span><span class="font-medium">{{ placeholderName }}</span> — {{ t('text.widget.edit_on_preview.loading_on_scroll') }}</span>
            </div>
        </div>
        <!-- The first preview could not be loaded: say so and offer another try, never an endless skeleton. -->
        <div v-else-if="failed && !srcdoc" class="p-4" role="alert" data-test="widget-preview-failed">
            <div class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-dashed border-[#c5cbd3] bg-[#f6f8f9] px-3 py-2 text-[13px] text-[#5b6470]">
                <span class="min-w-0 flex-1">{{ t('text.widget.edit_on_preview.preview_failed') }}</span>
                <button
                    type="button"
                    class="min-h-11 shrink-0 cursor-pointer border-0 bg-transparent px-1 font-medium text-[#0b6f7f] underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#0b6f7f] md:min-h-8"
                    @click="retry"
                >
                    {{ t('nav.b.retry') }}
                </button>
            </div>
        </div>
        <template v-else>
            <!-- Reserves the last known frame height, so loading and re-loading never collapse the block. -->
            <div class="relative" :style="{ minHeight: `${frameHeight}px` }" data-test="widget-preview-box">
                <!-- Mounted as soon as the HTML arrives: it has to render to report its height. -->
                <iframe
                    v-if="srcdoc"
                    ref="frame"
                    class="block w-full border-0 bg-white"
                    :style="{ height: `${frameHeight}px` }"
                    sandbox="allow-scripts"
                    tabindex="0"
                    :srcdoc="srcdoc"
                    @load="onFrameLoad"
                />
                <!-- First load: covers the frame until it reports its first height. -->
                <div v-if="!ready" class="absolute inset-0 overflow-hidden bg-white p-4" role="status" data-test="widget-preview-skeleton">
                    <div class="mb-3 truncate text-[13px] text-[#5b6470]">
                        <span class="font-medium">{{ placeholderName }}</span> — {{ t('text.widget.edit_on_preview.loading_preview') }}
                    </div>
                    <a-skeleton :active="!calm" :title="false" :paragraph="{ rows: 3 }" />
                </div>
                <!-- Re-load after an edit: the current preview stays, with a small spinner in the corner. -->
                <div
                    v-else-if="refreshing"
                    class="pointer-events-none absolute right-2 top-2 flex size-7 items-center justify-center rounded-full bg-white/90 text-[#0b6f7f] shadow-sm"
                    role="status"
                    data-test="widget-preview-refreshing"
                >
                    <svg viewBox="0 0 24 24" class="size-4 animate-spin motion-reduce:animate-none" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
                        <path d="M12 3a9 9 0 1 0 9 9" />
                    </svg>
                    <span class="sr-only">{{ t('text.widget.edit_on_preview.loading_preview') }}</span>
                </div>
            </div>
            <!-- z-index below ant modals (1000) so a picker modal opened from the field is never covered -->
            <a-popover
                v-if="editing && wide"
                :open="true"
                :z-index="999"
                overlay-class-name="[&_.ant-popover-inner]:rounded-lg! [&_.ant-popover-inner]:p-0! [&_.ant-popover-inner]:shadow-[0_14px_36px_rgba(0,0,0,.4)]! [&_.font-mono]:hidden"
                :placement="placement"
                trigger="click"
            >
                <template #content>
                    <div ref="editorBox" :class="panelWidth" class="max-w-[calc(100vw-2rem)]" @keydown.esc.stop="closeEditor">
                        <widget-preview-panel
                            :input="editingInput"
                            :values="panelValues"
                            :hint="t(`text.widget.edit_on_preview.${placement === 'bottomLeft' ? 'live_hint_above' : 'live_hint_beside'}`)"
                            @rich-change="onRichTextChange"
                            @image-change="onImageChange"
                            @fields-change="onFieldsChange"
                            @close="closeEditor"
                        />
                    </div>
                </template>
                <span
                    class="pointer-events-none absolute"
                    :style="{ left: `${anchor.x}px`, top: `${anchor.y}px`, width: `${anchor.width}px`, height: `${anchor.height}px` }"
                />
            </a-popover>
            <a-drawer
                v-else-if="editing"
                :open="true"
                placement="bottom"
                height="auto"
                :closable="false"
                :header-style="{ display: 'none' }"
                :body-style="{ padding: 0 }"
                :mask-style="{ background: 'rgba(0,0,0,.22)' }"
                :content-wrapper-style="{ maxHeight: '88vh', borderRadius: '16px 16px 0 0', overflow: 'hidden', boxShadow: '0 -10px 30px rgba(0,0,0,.3)' }"
                root-class-name="[&_button]:min-h-11 [&_button]:min-w-11 [&_.font-mono]:hidden"
                @close="closeEditor"
                @after-open-change="onAfterOpen"
            >
                <div ref="editorBox" @keydown.esc.stop="closeEditor">
                    <widget-preview-panel
                        touch
                        :input="editingInput"
                        :values="panelValues"
                        @rich-change="onRichTextChange"
                        @image-change="onImageChange"
                        @fields-change="onFieldsChange"
                        @close="closeEditor"
                    />
                </div>
            </a-drawer>
            <widget-preview-item-sheet
                v-if="srcdoc"
                v-bind="{ inputs, values, sheet, undo, phone, onNames }"
                @item="onItemMessage" @item-change="onSheetChange" @close="closeSheet" @undo="undoRemove"
            />
            <a-collapse
                v-if="srcdoc && offInputs.length"
                :bordered="false"
                class="rounded-none! border-0! border-t! border-solid! border-[#e5e7eb]! bg-[#fafbfc]! [&_.ant-collapse-content-box]:p-4! [&_.ant-collapse-content]:border-t! [&_.ant-collapse-content]:border-[#e5e7eb]! [&_.ant-collapse-content]:bg-white! [&_.ant-collapse-header]:min-h-[46px] [&_.ant-collapse-header]:items-center! [&_.ant-collapse-header]:px-4! [&_.ant-collapse-header]:py-2! [&_.ant-collapse-item]:border-0! [&_.font-mono]:hidden"
            >
                <a-collapse-panel key="off">
                    <template #header>
                        <span class="flex flex-wrap items-baseline gap-x-2.5 text-sm text-[#1f2933]">
                            <span class="font-semibold">{{ t('text.widget.edit_on_preview.other_settings') }} ({{ offInputs.length }})</span>
                            <span class="text-[13px] text-[#5b6470]">{{ offHint }}</span>
                        </span>
                    </template>
                    <widget-input-fields :inputs="offInputs" :model-value="values" @update:model-value="onOffChange" />
                </a-collapse-panel>
            </a-collapse>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { debounce, isEqual } from 'lodash-es'
import { useI18n } from 'vue-i18n'
import { getWidgetPreview } from '@/libs'
import { iAlert } from '@/mixins'
import WidgetInputFields from '@/components/widget-input-fields.vue'
import WidgetPreviewPanel from '@/components/widget-preview-panel.vue'
import WidgetPreviewItemSheet from '@/components/widget-preview-item-sheet.vue'
import { emptyValueForType, formatInputLabel } from '@/components/widget-editor-state'
import { buildEditableSrcdoc, isBridgeMessage, BRIDGE_SOURCE } from '@/components/widget-preview-bridge'
import { enqueuePreview } from '@/components/widget-preview-queue'
import {
    applyItemMessage, itemFieldLabels, itemFieldValue, itemsOf, replaceItem, repeaterOf, resolveItemField, setItemField, undoRemoval,
} from '@/components/widget-preview-repeater'

defineOptions({ name: 'WidgetPreviewEditor' })

const props = defineProps({
    widgetId: { type: Number, default: 0 },
    templateId: { type: Number, default: 0 },
    inputs: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    blockName: { type: String, default: '' },
})
// `blocked`: the server refused the preview because the widget is blocked on this site.
const emit = defineEmits(['update:modelValue', 'fields-ready', 'blocked'])
const { t, te } = useI18n()

const root = ref(null)
const frame = ref(null)
const started = ref(false)
const srcdoc = ref('')
const frameHeight = ref(160)
// First load: `ready` once the frame reported its first height (or a fallback timer gave up waiting);
// `failed` when the first request was refused or never answered. `refreshing`: a re-fetch is running.
const ready = ref(false)
const failed = ref(false)
const refreshing = ref(false)
const REQUEST_TIMEOUT_MS = 20000
const REPORT_TIMEOUT_MS = 8000
const LOADED_GRACE_MS = 1000
let loadTimer = null
const calm = !!window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches
const offNames = ref([])
const onNames = ref([])
// Repeater items: the last removal (undo bar, 8 s) and the item open in the phone sheet.
const UNDO_MS = 8000
const undo = ref(null)
let undoTimer = null
const sheet = ref(null)
const editing = ref(null)
const anchor = ref({ x: 0, y: 0, width: 0, height: 0 })
const wide = ref(true)
const placement = ref('bottomLeft')
let scrollY = 0
let sequence = 0
let inputSeq = 0
let unmounted = false
let intersected = false
const editorBox = ref(null)
let controller = null
let observer = null
// Last field edited in a popover/drawer: focus returns to it after the re-fetch reloads the frame.
let returnFocusName = null
let returnFocusItem = null
// Manifest of the current srcdoc, corrected once the frame reports which fields it really has.
let manifest = { onPreview: [], offPreview: [] }

const seed = (src) =>
    Object.fromEntries(props.inputs.map((i) => [i.name, src?.[i.name] ?? emptyValueForType(i.type)]))
const values = ref(seed(props.modelValue))
// Values this editor last emitted: a modelValue that differs came from outside (discard, new target).
let lastEmitted = { ...props.modelValue }

watch(
    () => props.modelValue,
    (model) => {
        if (isEqual(seed(model), seed(lastEmitted))) {
            values.value = seed({ ...values.value, ...model })
            return
        }
        // Outside reset: take it as-is and reload the frame so it does not show discarded text. A pending
        // undo or an open item sheet belongs to the discarded values (undo could duplicate an _id).
        undo.value = null
        clearTimeout(undoTimer)
        sheet.value = null
        values.value = seed(model)
        lastEmitted = { ...values.value }
        if (started.value) refetchSoon()
    },
    { deep: true }
)
// Inputs can arrive after mount (template inputs load late): re-seed so no field is missing.
watch(
    () => props.inputs,
    () => { values.value = seed({ ...values.value, ...props.modelValue }) },
    { deep: true }
)
watch(() => props.inputs.length, () => { if (intersected) start() })

const busy = computed(() => started.value && ((!ready.value && !failed.value) || refreshing.value))
const placeholderName = computed(() => props.blockName || `#${props.widgetId}`)
const plainInputs = computed(() =>
    props.inputs.map((i) => ({
        ...i,
        label: formatInputLabel(i, t('text.unknown.title'), { withToken: false, translate: (k) => (te(k) ? t(k) : '') }),
    }))
)
const labels = computed(() => ({
    ...Object.fromEntries(plainInputs.value.map((i) => [i.name, i.label])),
    ...itemFieldLabels(props.inputs, values.value),
}))
const itemField = (name) => resolveItemField(props.inputs, values.value, name)
const inputType = (name) => props.inputs.find((i) => i.name === name)?.type ?? itemField(name)?.field.type
const offInputs = computed(() => plainInputs.value.filter((i) => offNames.value.includes(i.name)))
// An item field ("NAME.ID.FIELD") opens the same editors under a flat name, titled with its item.
const editingItem = computed(() => itemField(editing.value))
const editingInput = computed(() => {
    const target = editingItem.value
    if (!target) return plainInputs.value.find((i) => i.name === editing.value) || null
    return { ...target.field, name: editing.value, label: `${target.field.label || target.field.name} · ${target.definition.itemLabel} ${target.index + 1}` }
})
const panelValues = computed(() =>
    editingItem.value ? { ...values.value, [editing.value]: itemFieldValue(values.value, editingItem.value) } : values.value
)
// Rich text and library images are edited in place (approved mockup), not through the field
// grid's preview + "Sửa" modal or the full-screen media centre.
const isRichText = computed(() => editingInput.value?.type === 'textarea')
const isMediaImage = computed(() => editingInput.value?.type === 'image' && editingInput.value?.options?.src === 'unknown')
const panelWidth = computed(() => (isRichText.value ? 'w-[404px]' : isMediaImage.value ? 'w-[480px]' : 'w-80'))
const offHint = computed(() =>
    t('text.widget.edit_on_preview.other_settings_hint', { fields: offInputs.value.map((i) => i.label).join(', ') })
)
// Shown in the frame next to the field label while a text field has the caret.
// Repeater copy: "add" keeps a literal {item} that the frame fills with each repeater's item label.
const frameStrings = computed(() => ({
    typing: t('text.widget.edit_on_preview.typing'), add: t('text.widget.repeater.add', { item: '{item}' }),
    ...Object.fromEntries(['duplicate', 'remove', 'drag'].map((k) => [k, t(`text.widget.repeater.${k}`)])),
}))

function setValues(next) {
    values.value = next
    lastEmitted = { ...next }
    emit('update:modelValue', { ...next })
}

async function fetchPreview() {
    controller?.abort()
    const ctl = (controller = new AbortController())
    const seq = ++sequence
    let startInput = inputSeq
    refreshing.value = ready.value
    try {
        const html = await enqueuePreview(() => {
            // Built at run time so a queued request carries the latest values.
            startInput = inputSeq
            // Nothing to show yet: counted from the real start of the request (not the wait in the queue).
            if (!srcdoc.value) waitFor(REQUEST_TIMEOUT_MS, () => { ctl.abort(); failFirstLoad(seq) })
            const params = { template_id: props.templateId, edit_markers: 1, ...values.value }
            // Silent: a refused preview is reported below (a blocked widget in the block's own words).
            return getWidgetPreview(props.widgetId, params, { signal: ctl.signal, silent: true })
        })
        if (unmounted || seq !== sequence) return
        // The user typed in the frame meanwhile: swapping now would drop the caret and stale text.
        if (startInput !== inputSeq) {
            refetchSoon()
            return
        }
        const built = buildEditableSrcdoc(html, labels.value, frameStrings.value)
        srcdoc.value = built.srcdoc
        // The frame may never report (script blocked, preview without edit markers): show it anyway.
        if (!ready.value) waitFor(REPORT_TIMEOUT_MS, reveal)
        setManifest(built.onPreview, built.offPreview)
    } catch (err) {
        if (!unmounted && !ctl.signal.aborted && err?.response?.data) {
            if (err.response.data.error_code === 'WIDGET_BLOCKED') emit('blocked')
            else iAlert(err.response.data, true)
        }
        // Aborted or failed: keep the previous preview. A failed first fetch has nothing to keep:
        // report no fields so the card falls back to the form.
        if (unmounted || seq !== sequence || ctl.signal.aborted || srcdoc.value) return
        failFirstLoad(seq)
    } finally {
        if (seq === sequence) refreshing.value = false
    }
}
function waitFor(ms, done) {
    clearTimeout(loadTimer)
    loadTimer = setTimeout(done, ms)
}
function reveal() {
    clearTimeout(loadTimer)
    ready.value = true
}
function failFirstLoad(seq) {
    if (unmounted || seq !== sequence || srcdoc.value || failed.value) return
    clearTimeout(loadTimer)
    failed.value = true
    setManifest([], [])
}
function retry() {
    failed.value = false
    fetchPreview()
}
function setManifest(onPreview, offPreview) {
    manifest = { onPreview, offPreview }
    onNames.value = onPreview
    offNames.value = offPreview
    emit('fields-ready', { onPreview, offPreview })
}
// Fields the HTML parser dropped (e.g. inside <option> or <svg>) cannot be clicked: list them
// under "Cài đặt khác" instead.
function onFieldsPresent(names) {
    if (!Array.isArray(names)) return
    const missing = manifest.onPreview.filter((n) => !names.includes(n))
    if (!missing.length) return
    setManifest(
        manifest.onPreview.filter((n) => names.includes(n)),
        [...manifest.offPreview, ...missing.filter((n) => !manifest.offPreview.includes(n))]
    )
}
let refetchPending = false
const refetchDebounced = debounce(() => {
    refetchPending = false
    fetchPreview()
}, 500)
const refetchSoon = () => {
    if (unmounted) return
    refetchPending = true
    refetchDebounced()
}

function onFrameLoad() {
    const win = frame.value?.contentWindow
    // Loaded but no height yet: the report comes right after load when it comes at all.
    if (!ready.value) waitFor(LOADED_GRACE_MS, reveal)
    win?.postMessage({ source: BRIDGE_SOURCE, type: 'restore-scroll', y: scrollY }, '*')
    // A reload while the popover is open (live rich text preview) must keep its field outlined.
    if (editing.value) markField(editing.value)
    // Keyboard return: only while the frame still has focus, so a reload never steals it.
    if (returnFocusName && document.activeElement === frame.value) {
        win?.postMessage({ source: BRIDGE_SOURCE, type: 'focus-field', name: returnFocusName }, '*')
        returnFocusName = null
    }
    // An item moved with Alt+arrows keeps the keyboard focus on the reloaded frame.
    if (returnFocusItem && document.activeElement === frame.value) {
        win?.postMessage({ source: BRIDGE_SOURCE, type: 'focus-item', ...returnFocusItem }, '*')
    }
    returnFocusItem = null
}

// Outline the field whose popover or sheet is open, like a focused field.
function markField(name) {
    frame.value?.contentWindow?.postMessage({ source: BRIDGE_SOURCE, type: 'mark-field', name }, '*')
}
// Beside the field when it sits in the right half of a wide frame, below it otherwise.
function choosePlacement(rect, type) {
    const box = frame.value?.getBoundingClientRect()
    if (!box || !rect) return 'bottomLeft'
    const need = (type === 'image' ? 480 : 404) + 24
    const beside = box.left + rect.x >= need && rect.x + rect.width / 2 > box.width / 2
    return beside ? (type === 'image' ? 'left' : 'leftTop') : 'bottomLeft'
}

function closeEditor() {
    if (!editing.value) return
    returnFocusName = editing.value
    editing.value = null
    markField(null)
    refetchSoon()
    frame.value?.focus()
}

// Writes a top-level field or, for "NAME.ID.FIELD", that item's field only.
function writeField(name, value) {
    const target = itemField(name)
    setValues(target ? setItemField(values.value, target, value) : { ...values.value, [name]: value })
}
function onFieldsChange(next) {
    if (editingItem.value) writeField(editing.value, next[editing.value])
    else setValues({ ...values.value, ...next })
}
function onRichTextChange(html) {
    if (!editingInput.value) return
    writeField(editingInput.value.name, html)
    // Typing happens in the parent document, so the frame can reload without losing the caret.
    refetchSoon()
}
function onImageChange(files) {
    if (!editingInput.value) return
    writeField(editingInput.value.name, files)
}
function onOffChange(next) {
    setValues({ ...values.value, ...next })
    refetchSoon()
}

function tryFocusEditor() {
    // The text or search box first; a control (close, toolbar) only when there is none.
    const el =
        editorBox.value?.querySelector('[contenteditable="true"],input:not([type=file]),textarea,select') ||
        editorBox.value?.querySelector('button,[tabindex]:not([tabindex="-1"])')
    el?.focus()
    return !!el
}
// a-popover mounts its content with display:none while it measures placement, and focus() on a
// hidden element is a no-op: wait until the box has a size, then focus so Tab and Esc work inside.
let sizeObserver = null
const stopSizeWatch = () => { sizeObserver?.disconnect(); sizeObserver = null }
watch(editorBox, (el) => {
    stopSizeWatch()
    if (!el || !editing.value) return
    if (typeof ResizeObserver === 'undefined') return void tryFocusEditor()
    sizeObserver = new ResizeObserver((entries) => {
        if (!entries.some((e) => e.contentRect.width > 0)) return
        stopSizeWatch()
        tryFocusEditor()
    })
    sizeObserver.observe(el)
}, { flush: 'post' })
function onAfterOpen(open) {
    if (open) tryFocusEditor()
}

function onMessage(event) {
    if (!isBridgeMessage(event, frame.value?.contentWindow)) return
    const d = event.data
    if (d.type === 'field-input') {
        // Only plain text fields are typed in the frame; anything else is not ours to write.
        if (inputType(d.name) !== 'text') return
        inputSeq++
        if (refetchPending) refetchSoon()
        writeField(d.name, String(d.value ?? ''))
    } else if (d.type === 'field-open') {
        if (!['textarea', 'image'].includes(inputType(d.name))) return
        wide.value = window.innerWidth >= 768
        anchor.value = d.rect || anchor.value
        placement.value = choosePlacement(d.rect, inputType(d.name))
        editing.value = d.name
        markField(d.name)
    } else if (d.type === 'size' && Number(d.height) > 0) {
        frameHeight.value = Number(d.height)
        reveal()
    }
    else if (d.type === 'escape') closeEditor()
    else if (d.type === 'scroll') scrollY = Number(d.y) || 0
    else if (d.type === 'fields-present') onFieldsPresent(d.names)
    else if (String(d.type).startsWith('item-')) onItemMessage(d)
}

// Repeater items: add, duplicate, remove (with undo), move; the phone opens one item in a sheet.
const phone = ref(window.innerWidth < 768)
const onResize = () => (phone.value = window.innerWidth < 768)
function onItemMessage(d) {
    if (d.type === 'item-open') {
        if (window.innerWidth < 768 && itemsOf(values.value, d.repeater).some((i) => i._id === d.id) && repeaterOf(props.inputs, d.repeater)) {
            sheet.value = { repeater: d.repeater, id: d.id }
        }
        return
    }
    const result = applyItemMessage(props.inputs, values.value, d)
    if (!result) return
    if (d.type === 'item-move') returnFocusItem = { repeater: d.repeater, id: d.id }
    setValues(result.values)
    if (result.removed) {
        if (sheet.value?.id === d.id) sheet.value = null
        clearTimeout(undoTimer)
        undo.value = { ...result.removed, label: `${result.removed.label} ${result.removed.index + 1}` }
        undoTimer = setTimeout(() => (undo.value = null), UNDO_MS)
    }
    refetchSoon()
}
function undoRemove() {
    if (!undo.value) return
    setValues(undoRemoval(values.value, undo.value))
    clearTimeout(undoTimer)
    undo.value = null
    refetchSoon()
}
function onSheetChange(item) {
    if (!sheet.value) return
    setValues(replaceItem(values.value, sheet.value.repeater, sheet.value.id, item))
    refetchSoon()
}
function closeSheet() {
    sheet.value = null
    refetchSoon()
}

function start() {
    intersected = true
    if (started.value || !props.inputs.length) return
    started.value = true
    observer?.disconnect()
    fetchPreview()
}

onMounted(() => {
    window.addEventListener('message', onMessage)
    window.addEventListener('resize', onResize)
    observer = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && start(),
        { rootMargin: '200px' }
    )
    observer.observe(root.value)
})
onBeforeUnmount(() => {
    unmounted = true
    window.removeEventListener('message', onMessage)
    window.removeEventListener('resize', onResize)
    clearTimeout(undoTimer)
    clearTimeout(loadTimer)
    observer?.disconnect()
    stopSizeWatch()
    refetchDebounced.cancel()
    controller?.abort()
})
</script>
