<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div ref="rootEl" class="block bg-white">
        <label v-if="!noLabel">
            <span v-if="required" class="text-red-600">*</span>
            {{ $t('validation.attributes.content') }}
        </label>
        <div class="space-y-4">
            <div
                v-if="lastDeletedBlock"
                class="flex items-center justify-between gap-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                role="status"
            >
                <span>{{ $t('text.editor.contents.block_deleted') }}</span>
                <a-button type="link" size="small" @click="undoDelete">
                    {{ $t('text.form.undo') }}
                </a-button>
            </div>
            <!-- A widget handed over from the widget library could not be placed: say why, in plain words. -->
            <div
                v-if="initialNotice && initialNotice.status !== 'added'"
                class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                data-test="initial-widget-notice"
                role="alert"
            >
                <span class="min-w-0 break-words">
                    {{
                        initialNotice.status === 'blocked'
                            ? t('text.widget_library.page_blocked', { name: initialNotice.name })
                            : t('text.widget_library.page_missing')
                    }}
                    <template v-if="initialNotice.reason">
                        {{ t('text.widget_library.block_reason') }}: {{ initialNotice.reason }}
                    </template>
                </span>
                <a-button type="link" size="small" @click="initialNotice = null">
                    {{ $t('nav.b.close') }}
                </a-button>
            </div>
            <div
                v-if="config.translation_last_try"
                class="border border-dashed border-rose-600/70 rounded-sm p-4 mb-3 bg-rose-50"
            >
                <div>
                    {{
                        $t('text.editor.contents.translation_requested', {
                            count: config.translation_total_try,
                            last: config.translation_last_try,
                        })
                    }}
                </div>
                <div class="flex items-center space-x-2">
                    <label for="">{{ $t('text.editor.contents.translation_queued') }}</label>
                    <a-form-item no-style :name="['config', 'translation_on_queue']">
                        <a-switch v-model:checked="config.translation_on_queue" />
                    </a-form-item>
                </div>
            </div>

            <draggable
                v-model="form"
                item-key="id"
                handle=".drag-handle"
                class="space-y-4"
                :animation="200"
                :clone="cloneItem"
                @start="onDragStart"
                @end="onDragEnd"
                @update:modelValue="onDragUpdate"
            >
                <template #item="{ element: ct, index: ind, isDragging }">
                    <div
                        :class="[
                            'relative ',
                            `ct-${ct.type}`,
                            ct.type === 'widget' ? 'border-rose-400' : 'border-gray-400',
                            isDragging ? 'bg-white shadow-md' : 'bg-white',
                            addedBlockId === ct.id && initialNotice
                                ? 'rounded-lg ring-2 ring-cyan-600 ring-offset-2 focus:outline-hidden'
                                : '',
                        ]"
                        :data-block-id="ct.id"
                        :tabindex="addedBlockId === ct.id ? -1 : undefined"
                    >
                        <!-- The block the widget library just placed here: nothing is stored until the page is saved. -->
                        <div
                            v-if="addedBlockId === ct.id && initialNotice"
                            class="mb-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm text-cyan-900"
                            data-test="initial-widget-notice"
                            role="status"
                        >
                            <span class="min-w-0 break-words">
                                {{ t('text.widget_library.page_added', { name: initialNotice.name }) }}
                            </span>
                            <a-button type="link" size="small" @click="initialNotice = null">
                                {{ $t('nav.b.close') }}
                            </a-button>
                        </div>
                        <div
                            v-if="
                                (form.length > 1 || contentBlockExtensionFor(ct.type)) &&
                                !(widgetHeaderControls && ct.type === 'widget')
                            "
                            class="drag-handle absolute z-10 right-0 translate-x-1/2 rounded-sm shadow-sm -top-2 cursor-move bg-white px-1 py-1 border space-y-1.5 border-gray-400 flex flex-col items-center"
                        >
                            <i-icon class="size-4 text-gray-500" icon="arrow-up-down" />

                            <button
                                type="button"
                                :aria-label="$t('text.editor.contents.delete_block')"
                                class="text-red-600 cursor-pointer hover:text-red-700"
                                @click="confirmDelete(ind, ct)"
                            >
                                <i-icon class="size-4" icon="delete" />
                            </button>
                        </div>

                        <tip-tap-editor
                            v-if="ct.type === 'text' || ct.type === 'glossary'"
                            v-model="form[ind].value"
                            :original="form[ind]"
                            @change="(val) => updateContent(ind, val)"
                        />

                        <div
                            v-else-if="ct.type === 'video'"
                            class="block rounded-md border border-gray-400 p-2"
                        >
                            <i-content-video v-model="form" :index="ind" @change="updateVideo" />
                        </div>

                        <div
                            v-else-if="ct.type === 'image'"
                            class="block rounded-md border border-gray-400 p-2"
                        >
                            <!-- (keep ModalMediaCenter content unchanged) -->
                            <ModalMediaCenter
                                v-model="form[ind].value"
                                v-model:show="mediaShowState[ind]"
                                :limit="2"
                                @update:model-value="() => updateModel()"
                            >
                                <template #children>
                                    <div
                                        class="flex flex-col md:flex-row items-start md:space-x-4 space-y-4 md:space-y-0"
                                    >
                                        <div>
                                            <div class="flex items-center space-x-3">
                                                <div
                                                    v-for="(img, idx) in form[ind].value"
                                                    v-if="form[ind].value.length > 0"
                                                    :key="idx"
                                                    class="relative border w-16 h-16 rounded-sm bg-gray-100 text-center shadow-xs"
                                                >
                                                    <div
                                                        v-if="img"
                                                        class="rounded-sm overflow-hidden"
                                                    >
                                                        <img
                                                            v-if="typeof img === 'object' && img.thumbnail_sm"
                                                            :src="img.thumbnail_sm"
                                                            alt=""
                                                            class="w-full h-full object-contain"
                                                        />
                                                        <div
                                                            v-else
                                                            class="w-full h-full flex items-center justify-center text-xs text-amber-600 bg-amber-50"
                                                            :title="typeof img === 'string' ? img : $t('text.media.pending')"
                                                        >
                                                            <i-icon class="size-4" icon="cloud-download" />
                                                        </div>
                                                    </div>
                                                </div>
                                                <label
                                                    v-else
                                                    class="rounded-sm text-center text-xl w-16 h-16 py-4 px-3 text-indigo-500/50 bg-slate-50 border-2 border-dashed border-indigo-200"
                                                    role="button"
                                                >
                                                    <i-icon class="size-4" icon="images" />
                                                </label>

                                                <label
                                                    class="inline-flex rounded-sm transition-all w-16 h-16 items-center p-3 shadow-xs text-cyan-600 bg-slate-50 hover:bg-cyan-100 hover:text-slate-500 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 border border-indigo-100"
                                                    role="button"
                                                    @click="mediaShowState[ind] = true"
                                                >
                                                    <span
                                                        class="text-xl mx-auto text-center leading-6"
                                                    >
                                                        <i-icon
                                                            class="size-4"
                                                            icon="cloud-upload"
                                                        />
                                                        <b class="text-sm font-normal">{{
                                                            $t('text.select')
                                                        }}</b>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="flex-1">
                                            <div
                                                v-if="ct.value && ct.value[0] && typeof ct.value[0] === 'object'"
                                                class="grid grid-cols-2 gap-y-4 md:gap-y-0 md:gap-x-4"
                                            >
                                                <div class="col-span-2 md:col-span-1">
                                                    <a-form-item
                                                        :name="[
                                                            'content',
                                                            `${ind}`,
                                                            'options',
                                                            'caption',
                                                        ]"
                                                    >
                                                        <template #label>
                                                            <div class="flex items-center">
                                                                <span class="flex-1">
                                                                    {{ $t('text.image.caption') }}
                                                                </span>
                                                                <i-icon
                                                                    class="size-4 text-gray-500"
                                                                    icon="information"
                                                                />
                                                            </div>
                                                        </template>
                                                        <a-input
                                                            v-model:value="
                                                                form[ind].options.caption
                                                            "
                                                            :placeholder="$t('text.editor.caption_placeholder')"
                                                            type="text"
                                                            @input="() => updateModel()"
                                                        />
                                                        <p class="text-xs m-0 text-gray-500">
                                                            {{ $t('text.image.caption-desc') }}
                                                        </p>
                                                    </a-form-item>
                                                </div>

                                                <div class="col-span-2 md:col-span-1">
                                                    <a-form-item
                                                        :label="$t('text.image.alt')"
                                                        :name="[
                                                            'content',
                                                            `${ind}`,
                                                            'options',
                                                            'alt',
                                                        ]"
                                                    >
                                                        <a-input
                                                            v-model:value="form[ind].options.alt"
                                                            :placeholder="$t('text.editor.alt_placeholder')"
                                                            type="text"
                                                            @input="() => updateModel()"
                                                        />
                                                        <p class="text-xs m-0 text-gray-500">
                                                            {{ $t('text.image.alt-desc') }}
                                                        </p>
                                                    </a-form-item>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </ModalMediaCenter>
                        </div>

                        <div
                            v-else-if="contentBlockExtensionFor(ct.type)"
                            class="block rounded-md border border-gray-400 p-2"
                        >
                            <component
                                :is="contentBlockExtensionFor(ct.type).component"
                                :model-value="form[ind]"
                                @update:modelValue="(value) => updateExtensionBlock(ind, value)"
                            />
                        </div>

                        <div
                            v-else-if="
                                ct.type === 'widget' &&
                                rule('widget.viewAny')
                            "
                            class="block"
                        >
                            <i-content-widget-v2
                                v-model="form[ind]"
                                :target-id="targetId"
                                :removable="form.length > 1"
                                :pick-on-load="addedBlockId === ct.id"
                                @change="(val) => updateVideo(val, ind)"
                                @remove="confirmDelete(ind, ct)"
                            />
                        </div>

                        <div
                            v-else
                            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800"
                        >
                            {{ $t('text.editor.contents.no_editor', { type: ct.type }) }}
                        </div>
                    </div>
                </template>
            </draggable>

            <div
                v-if="limit === 0 || form.length <= limit"
                class="flex flex-wrap items-center text-center gap-2"
            >
                <a-button
                    v-if="plugins.includes('text')"
                    class="text-xs btn-success plain flex items-center space-x-1"
                    @click="addContent('text')"
                    size="small"
                >
                    <i-icon class="size-3.5" icon="file-document" />
                    <span class="m-0">{{ $t('nav.b.addText') }}</span>
                </a-button>
                <a-button
                    v-if="plugins.includes('image')"
                    class="text-xs btn-info plain flex items-center space-x-1"
                    @click="addContent('image')"
                    size="small"
                >
                    <i-icon class="size-3.5" icon="images" />
                    <span class="m-0">{{ $t('nav.b.insert-image') }}</span>
                </a-button>
                <a-button
                    v-if="plugins.includes('video')"
                    class="text-xs btn-danger plain flex items-center space-x-1"
                    @click="addContent('video')"
                    size="small"
                >
                    <i-icon class="size-3.5" icon="video-plus" />
                    <span class="m-0">{{ $t('nav.b.insert-video') }}</span>
                </a-button>
                <a-button
                    v-if="plugins.includes('glossary')"
                    class="text-xs btn-success plain flex items-center space-x-1"
                    @click="addContent('glossary')"
                    size="small"
                >
                    <i-icon class="size-3.5" icon="book-open" />
                    <span class="m-0">{{ $t('nav.b.addGlossary') }}</span>
                </a-button>
                <a-button
                    v-if="rule('widget.viewAny') && plugins.includes('widget')"
                    class="text-xs btn-danger plain flex items-center space-x-1"
                    @click="addContent('widget')"
                    size="small"
                >
                    <i-icon class="size-3.5" icon="widgets" />
                    <span class="m-0">{{ $t('nav.b.addWidget') }}</span>
                </a-button>
                <a-button
                    v-for="extension in addableContentBlockExtensions"
                    :key="extension.key"
                    class="text-xs btn-info plain flex items-center space-x-1"
                    size="small"
                    @click="addContent(extension.type)"
                >
                    <i-icon class="size-3.5" :icon="extension.icon || 'widgets'" />
                    <span class="m-0">{{ t(extension.label) }}</span>
                </a-button>
            </div>
        </div>
    </div>
</template>
<script setup>
import draggable from 'vuedraggable'
import { computed, defineComponent, nextTick, onMounted, ref, toRefs, watch } from 'vue'
import IContentVideo from './i-content-video'
import IContentWidgetV2 from './i-content-widget-v2.vue'
import TipTapEditor from '@/components/tiptap-editor.vue'
import { rule } from '@/mixins'
import { getTemplates, searchFilterWidgets } from '@/libs'
import { widgetLabel } from '@/libs/widget-names'
import { useI18n } from 'vue-i18n'
import { isEqual } from 'lodash-es'
import { adminContentBlockExtensions } from '@/config/admin-modules'
import { useActiveModules } from '@/hooks/useActiveModules'

const { t } = useI18n()
// With edit-on-preview (on unless VITE_WIDGET_EDIT_ON_PREVIEW=false) the widget card carries its own grip and delete
// button in its header.
const widgetHeaderControls = import.meta.env.VITE_WIDGET_EDIT_ON_PREVIEW !== 'false'
const { isModuleExtensionAllowed } = useActiveModules()
const contentBlockExtensions = computed(() =>
    adminContentBlockExtensions.filter((extension) => isModuleExtensionAllowed(extension.module))
)
const contentBlockExtensionFor = (type) =>
    contentBlockExtensions.value.find((extension) => extension.type === type)
const addableContentBlockExtensions = computed(() =>
    contentBlockExtensions.value.filter((extension) =>
        extension.addable && (!extension.targets || extension.targets.includes(props.target))
    )
)

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => {
            return [{ type: 'text', value: null }]
        },
    },
    language_id: { type: Number, default: 0 },
    //  Limit of content type object
    limit: {
        type: Number,
        default: (val) => {
            return val >>> 0
        },
    },
    //  Upload target folder
    target: { type: String, default: 'News' },
    targetId: {
        type: Number,
        default: (e) => {
            return e >>> 0
        },
    },
    //  Allow plugins of contents
    plugins: {
        type: Array,
        default: () => {
            return ['text', 'image', 'video', 'glossary', 'widget']
        },
    },
    required: { type: Boolean, default: false },
    noLabel: { type: Boolean, default: false },
    config: { type: Object, default: {} },
    // A widget picked in the widget library for this page: `{ widgetId, templateId }`. Placed once as a new block
    // at the end; the page clears it after `initial-widget-handled`.
    initialWidget: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue', 'change', 'initial-widget-handled'])

defineComponent({
    name: 'IContent',
    components: {
        'i-content-widget-v2': IContentWidgetV2,
    },
})

const confirmDelete = (index, obj) => {
    const hasData =
        (obj.type === 'text' && obj.value?.trim()) ||
        (obj.type === 'glossary' && obj.value?.trim()) ||
        (obj.type === 'video' && obj.value?.trim()) ||
        (obj.type === 'image' && obj.value?.length > 0) ||
        (obj.type === 'widget' && obj.widget_id && obj.template_id) ||
        (contentBlockExtensionFor(obj.type) && obj.value)

    if (hasData) {
        if (confirm(t('text.del-confirm'))) {
            itemDel(index, obj)
        }
    } else {
        itemDel(index, obj)
    }
}

const deepClone = (obj) => {
    return JSON.parse(JSON.stringify(obj))
}

let uid = 0
const lastDeletedBlock = ref(null)
const normalizeContentBlock = (block, index) => {
    const normalized = deepClone(block || {})
    if (!normalized.id) {
        normalized.id = `content_${props.targetId || 'draft'}_${index}`
    }
    if (normalized.type === 'image' && !normalized.options) {
        normalized.options = { caption: null, alt: null, title: null, description: null }
    }
    return normalized
}

const { modelValue } = toRefs(props)
const cloneItem = (original) => {
    return deepClone(original)
}
const form = ref(
    deepClone(modelValue.value || [{ type: 'text', value: null }]).map(normalizeContentBlock)
)
const mediaShowState = ref([false])

// Widget handed over by the widget library ("Thêm vào… → Trang"): one new block at the end, in the editor only.
const rootEl = ref(null)
const addedBlockId = ref(null)
// { status: 'added' | 'blocked' | 'missing', name, reason }
const initialNotice = ref(null)
let initialWidgetTaken = false

const showAddedBlock = async (id) => {
    await nextTick()
    const block = [...(rootEl.value?.querySelectorAll('[data-block-id]') || [])].find(
        (el) => el.dataset.blockId === id
    )
    if (!block) return
    const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches
    block.scrollIntoView?.({ block: 'center', behavior: calm ? 'auto' : 'smooth' })
    block.focus?.({ preventScroll: true })
}

const placeInitialWidget = async () => {
    const widgetId = Number(props.initialWidget?.widgetId) || 0
    const templateId = Number(props.initialWidget?.templateId) || 0
    if (initialWidgetTaken || !props.initialWidget) return
    initialWidgetTaken = true

    const finish = (status, widget = null) => {
        initialNotice.value = {
            status,
            name: widgetLabel(widget),
            reason: status === 'blocked' ? widget?.blocked_reason || '' : '',
        }
        emit('initial-widget-handled', { status })
    }

    if (widgetId < 1 || templateId < 1) return finish('missing')
    // Without the widget permission (or a block list without widgets) nothing is asked: one notice, no refused request.
    if (!rule('widget.viewAny') || !props.plugins.includes('widget')) return finish('missing')

    let widget = null
    let templates = []
    try {
        // Read fresh, by id: the picker list is paged and may be older than a block set a minute ago.
        const [found, allTemplates] = await Promise.all([
            searchFilterWidgets({ id: widgetId, limit: 1 }),
            getTemplates({}),
        ])
        const rows = Array.isArray(found) ? found : found?.data || []
        widget = rows.find((row) => Number(row.id) === widgetId) || null
        templates = Array.isArray(allTemplates) ? allTemplates : allTemplates?.data || []
    } catch {
        // The request already reported its error: nothing is placed.
    }
    const usable =
        rule('widget.viewAny') &&
        props.plugins.includes('widget') &&
        templates.some((row) => Number(row.id) === templateId && Number(row.widget_id) === widgetId)

    if (!widget || !usable) return finish('missing')
    if (widget.is_blocked === true || widget.blocked_at) return finish('blocked', widget)

    const id = `content_added_${Date.now()}`
    form.value = [
        ...form.value,
        { id, type: 'widget', value: {}, options: {}, widget_id: widgetId, template_id: templateId, inputs: [] },
    ]
    addedBlockId.value = id
    finish('added', widget)
    showAddedBlock(id)
}

onMounted(() => watch(() => props.initialWidget, placeInitialWidget, { immediate: true }))

//  Add new Item
const addContent = (e) => {
    const id = `content_${uid++}`
    const extension = contentBlockExtensionFor(e)
    let data = extension?.createBlock
        ? extension.createBlock(id)
        : { id, type: e, value: null, options: {} }
    if (e === 'video') {
        data.options = { desktopHeight: 450, mobileHeight: 300 }
    }
    if (e === 'image') {
        data.value = []
        data.options = {
            caption: null,
            alt: null,
            title: null,
            description: null,
        }
    }
    if ((e === 'text' || e === 'glossary') && findTranslated()) {
        data.is_translated = false
    }
    if (e === 'widget') {
        data.widget_id = null
        data.template_id = null
        data.inputs = []
    }

    form.value.push(data)
    form.value = [...form.value] // Trigger reactivity by replacing the whole array
    nextTick(updateModel)
}

const onDragStart = () => {
    document.body.classList.add('dragging')
    // Disable all contenteditable elements during drag
    document.querySelectorAll('[contenteditable="true"]').forEach((el) => {
        el.classList.add('editor-disabled')
        el.setAttribute('contenteditable', 'false')
        el.dataset._wasContentEditable = 'true'
    })
}

const onDragEnd = () => {
    document.body.classList.remove('dragging')
    // Restore contenteditable to previous state
    document.querySelectorAll('[data-_was-content-editable]').forEach((el) => {
        el.classList.remove('editor-disabled')
        el.setAttribute('contenteditable', 'true')
        el.removeAttribute('data-_was-content-editable')
    })

    // Also trigger model update
    onDragUpdate()
}

/**
 * Delete an item from Array | Object
 *-------------------
 * @param {Number} index - Index of Array|Object to delete
 * @param obj
 * @return {Array}
 **/
const itemDel = (index = 0, obj = {}) => {
    lastDeletedBlock.value = {
        index,
        block: deepClone(obj),
    }
    form.value.splice(index, 1)
    nextTick(updateModel)
}

const undoDelete = () => {
    if (!lastDeletedBlock.value) return

    const { index, block } = lastDeletedBlock.value
    form.value.splice(Math.min(index, form.value.length), 0, deepClone(block))
    lastDeletedBlock.value = null
    nextTick(updateModel)
}

//  Find any item type=text has is_translated property
const findTranslated = () => {
    return form.value.find((e) => (e.type === 'text' || e.type === 'glossary') && e.hasOwnProperty('is_translated'))
}

const updateVideo = (value, index) => {
    form.value[index] = value

    nextTick(() => {
        updateModel()
    })
}
const onDragUpdate = () => {
    // Replace array to force reactivity and emit update
    form.value = [...form.value]
    nextTick(updateModel)
}

const updateContent = (index, value) => {
    form.value[index].value = value
    nextTick(updateModel)
}

const updateExtensionBlock = (index, value) => {
    form.value[index] = {
        ...value,
        id: form.value[index]?.id || value?.id,
    }
    nextTick(updateModel)
}

/** Update model value */
const updateModel = () => {
    const formValue = deepClone(form.value)

    emit('update:modelValue', formValue)
    emit('change', formValue)
}

watch(
    () => form.value,
    () => {
        updateModel()
    },
    { deep: true }
)

watch(
    () => props.modelValue,
    (val) => {
        const normalizedVal = (val || [{ type: 'text', value: null }]).map(normalizeContentBlock)
        if (!isEqual(form.value, normalizedVal)) {
            form.value = normalizedVal
        }
    },
    { deep: true }
)
</script>
