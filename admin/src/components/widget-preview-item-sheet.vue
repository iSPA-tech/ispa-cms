<!--
    Repeater item controls of the widget live preview, below the frame: the phone bottom sheet for one
    item (all its fields, Lên / Xuống, Nhân bản, Xoá, Xong), the undo bar after a removal and the
    phone "+ Thêm …" buttons. Display only: every change goes back to widget-preview-editor as an event.
-->
<template>
    <!-- Phone: one item in a bottom sheet with all its fields and the item actions (approved Mobile board).
         Buttons get their 48px through the drawer root rule: a utility on the button loses to it. -->
    <a-drawer
        v-if="sheetItem"
        :open="true"
        placement="bottom"
        height="auto"
        :closable="false"
        :header-style="{ display: 'none' }"
        :body-style="{ padding: 0 }"
        :mask-style="{ background: 'rgba(0,0,0,.22)' }"
        :content-wrapper-style="{ maxHeight: '88vh', borderRadius: '16px 16px 0 0', overflow: 'hidden', boxShadow: '0 -10px 30px rgba(0,0,0,.3)' }"
        root-class-name="[&_button]:min-h-12 [&_button]:min-w-11 [&_.font-mono]:hidden"
        @close="emit('close')"
    >
        <div class="flex flex-col gap-3 px-4 pb-7 pt-2 text-sm text-[#1f2933]" data-test="preview-item-sheet" @keydown.esc.stop="emit('close')">
            <div class="h-1 w-10 self-center rounded-sm bg-[#c5cbd3]" aria-hidden="true" />
            <div class="text-base font-semibold leading-[22px]">{{ sheetItem.title }}</div>
            <widget-input-fields :inputs="sheetItem.fields" :model-value="sheetItem.item" @update:model-value="(item) => emit('item-change', item)" />
            <div class="grid grid-cols-2 gap-2">
                <button v-for="b in sheetButtons" :key="b.action" type="button" :data-action="b.action" :disabled="b.disabled" :class="sheetButton" @click="sheetAction(b.action)">
                    {{ b.label }}
                </button>
            </div>
            <button type="button" data-action="done" class="w-full cursor-pointer rounded-lg border-0 bg-[#0f7f91] text-base font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]" @click="emit('close')">
                {{ t('text.widget.edit_on_preview.done') }}
            </button>
        </div>
    </a-drawer>
    <div role="status" class="empty:hidden">
        <div v-if="undo" class="border-t border-[#e5e7eb] px-4 py-2">
            <button type="button" data-test="preview-undo" class="min-h-11 w-full cursor-pointer rounded-lg border-0 bg-[#1f2933] px-3 text-left text-sm text-white hover:bg-[#374151] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]" @click="emit('undo')">
                {{ t('text.widget.repeater.undo', { item: undo.label }) }}
            </button>
        </div>
    </div>
    <div v-if="phone && phoneAdds.length" class="flex flex-col gap-2 border-t border-[#e5e7eb] px-4 py-3">
        <button v-for="r in phoneAdds" :key="r.name" type="button" data-test="preview-item-add" class="min-h-12 w-full cursor-pointer rounded-lg border border-dashed border-[#17a2b8] bg-white text-[15px] font-semibold text-[#0b6f7f] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]" @click="emit('item', { type: 'item-add', repeater: r.name })">
            {{ t('text.widget.repeater.add', { item: r.itemLabel }) }}
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import WidgetInputFields from '@/components/widget-input-fields.vue'
import { itemsOf, repeaterOf } from '@/components/widget-preview-repeater'

defineOptions({ name: 'WidgetPreviewItemSheet' })

const props = defineProps({
    inputs: { type: Array, default: () => [] },
    values: { type: Object, default: () => ({}) },
    // { repeater, id } of the item open in the sheet, or null.
    sheet: { type: Object, default: null },
    // { label } of the last removed item while it can be undone, or null.
    undo: { type: Object, default: null },
    phone: { type: Boolean, default: false },
    onNames: { type: Array, default: () => [] },
})
// item: a frame-style item message ({ type: 'item-…', repeater, id, toIndex }).
const emit = defineEmits(['item', 'item-change', 'close', 'undo'])
const { t } = useI18n()

const sheetItem = computed(() => {
    const rep = props.sheet && repeaterOf(props.inputs, props.sheet.repeater)
    const items = rep ? itemsOf(props.values, props.sheet.repeater) : []
    const index = items.findIndex((i) => i._id === props.sheet?.id)
    if (index < 0) return null
    const fields = rep.definition.fields.map((f) => ({ ...f, label: f.label || f.name }))
    return { ...props.sheet, index, count: items.length, item: items[index], fields, max: rep.definition.max, title: `${rep.definition.itemLabel} ${index + 1}` }
})
const sheetButton =
    'cursor-pointer rounded-lg border border-solid border-[#d0d5db] bg-white text-[15px] font-medium text-[#1f2933] disabled:cursor-not-allowed disabled:bg-[#f3f4f6] disabled:text-[#6b7280] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0b6f7f]'
const sheetButtons = computed(() => {
    const s = sheetItem.value
    return [
        { action: 'up', label: t('text.widget.repeater.up'), disabled: s.index === 0 },
        { action: 'down', label: t('text.widget.repeater.down'), disabled: s.index === s.count - 1 },
        { action: 'duplicate', label: t('text.widget.repeater.duplicate'), disabled: s.count >= s.max },
        { action: 'remove', label: t('text.widget.repeater.remove'), disabled: false },
    ]
})
function sheetAction(action) {
    const s = sheetItem.value
    const toIndex = action === 'up' ? s.index - 1 : s.index + 1
    emit('item', { type: action === 'up' || action === 'down' ? 'item-move' : `item-${action}`, repeater: s.repeater, id: s.id, toIndex })
}
// "+ Thêm …" under the block on phones, where the frame hides its add tile.
const phoneAdds = computed(() =>
    props.inputs
        .filter((i) => i.type === 'repeater' && props.onNames.includes(i.name))
        .map((i) => ({ name: i.name, ...repeaterOf(props.inputs, i.name).definition, count: itemsOf(props.values, i.name).length }))
        .filter((r) => r.count < r.max)
)
</script>
