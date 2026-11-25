<!--
  Step picker of the workflow designer: opens where an output was dropped on the empty canvas, or beside a clicked
  output, and adds the chosen step already connected. It is a plain box inside the canvas (an Ant Design popover
  would trap the focus of the diagram).
-->
<template>
    <div
        ref="box"
        class="absolute z-30 flex max-h-[min(420px,calc(100%-16px))] w-[320px] max-w-[calc(100%-16px)] flex-col rounded-xl border border-solid border-slate-300 bg-white shadow-xl"
        :style="{ left: `${left}px`, top: `${top}px` }"
        role="dialog"
        :aria-label="t('text.workflow.canvas.picker_title')"
        data-testid="step-picker"
        @keydown.esc.stop.prevent="$emit('close')"
    >
        <div class="flex items-center gap-2 border-0 border-b border-solid border-slate-200 p-2">
            <a-input
                ref="searchField"
                v-model:value="search"
                allow-clear
                :aria-label="t('text.workflow.palette.search')"
                :placeholder="t('text.workflow.palette.search')"
                data-testid="step-picker-search"
            >
                <template #prefix>
                    <i-icon icon="magnify" class="h-4 w-4 text-gray-400" />
                </template>
            </a-input>
            <a-button :aria-label="t('text.workflow.canvas.close')" :title="t('text.workflow.canvas.close')" type="text" data-testid="step-picker-close" @click="$emit('close')">
                <i-icon icon="close" class="h-4 w-4" />
            </a-button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-2">
            <div v-if="catalog.loading && !groups.length" class="flex justify-center py-4">
                <a-spin size="small" />
            </div>
            <p v-else-if="catalog.failed && !groups.length" class="m-0 py-3 text-center text-sm text-red-700" data-testid="step-picker-error">
                {{ t('text.workflow.canvas.picker_failed') }}
            </p>
            <p v-else-if="!groups.length" class="m-0 py-3 text-center text-sm text-slate-600" data-testid="step-picker-empty">
                {{ search ? t('text.workflow.palette.no_match') : t('text.workflow.palette.empty') }}
            </p>
            <section v-for="group in groups" :key="group.key" class="mb-2 last:mb-0">
                <p class="mb-1 flex items-center gap-1.5 px-1 text-xs font-semibold text-slate-600">
                    <i-icon :icon="group.icon" class="h-4 w-4" />
                    {{ group.label }}
                </p>
                <ul class="m-0 grid list-none gap-1 p-0">
                    <li v-for="node in group.nodes" :key="node.type">
                        <button
                            type="button"
                            class="w-full cursor-pointer rounded-lg border border-solid border-transparent bg-white px-2 py-1.5 text-left hover:border-sky-300 hover:bg-sky-50 focus-visible:border-sky-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-200"
                            :data-node-type="node.type"
                            @click="$emit('pick', buildDropPayload(node, node.title, usedCodes))"
                        >
                            <span class="block break-words text-sm font-semibold text-slate-900">{{ node.title }}</span>
                            <span v-if="node.summary" class="line-clamp-2 block break-words text-xs text-slate-600">{{ node.summary }}</span>
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { buildDropPayload, buildPaletteGroups, useNodeCatalog } from './nodeCatalog'

defineOptions({
    name: 'StepPicker',
})

defineProps({
    /** Place of the box inside the canvas, in pixels. */
    left: { type: Number, default: 8 },
    top: { type: Number, default: 8 },
    /** Step codes already on the canvas, so the new step gets its own. */
    usedCodes: { type: Array, default: () => [] },
})

const emit = defineEmits(['pick', 'close'])

const { t } = useI18n()
const { catalog } = useNodeCatalog()

const search = ref('')
const box = ref(null)
const searchField = ref(null)

// A step added from an output continues the workflow, so the steps that start one are left out.
const groups = computed(() => buildPaletteGroups(catalog, search.value, t).filter((group) => group.key !== 'trigger'))

const closeOnOutside = (event) => {
    if (box.value && !box.value.contains(event.target)) emit('close')
}

onMounted(() => {
    nextTick(() => searchField.value?.focus?.())
    // The click that opened the picker must not close it.
    setTimeout(() => document.addEventListener('pointerdown', closeOnOutside, true), 0)
})
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOnOutside, true))
</script>
