<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-popover v-model:open="popoverState" placement="left" trigger="click">
        <template #content>
            <section class="v-table-grid p-0!">
                <header>
                    <button
                        class="bg-black/60 text-white hover:bg-black/80 w-full text-sm leading-7 mb-1"
                        type="button"
                        @click="unSetColor"
                    >
                        {{ t('text.form.default-background') }}
                    </button>
                </header>

                <div v-for="(row, i) in colorSet" :key="`row-${i}`" class="v-row color-row">
                    <div v-for="(col, j) in row" :key="`col-${j}`" class="v-row color-column">
                        <a-tooltip>
                            <template #title>{{ col.hex }} {{ col.rgb }}</template>
                            <div
                                class="v-row justify-center items-center cell-container cursor-pointer"
                                @click="onSelect(col)"
                            >
                                <div
                                    :class="{ border: col.border }"
                                    :style="{ backgroundColor: col.hex }"
                                    class="row justify-center items-center cell"
                                />
                            </div>
                        </a-tooltip>
                    </div>
                </div>
            </section>
        </template>

        <button
            :class="
                classNames(
                    isActive(toolbar.name) ? 'bg-cyan-600 text-white' : '',
                    'hover:bg-gray-200 hover:text-gray-900 text-gray-600 text-center text-base rounded-sm',
                    'w-6 h-6 inline-flex items-center justify-center'
                )
            "
            @click="onOpen"
        >
            <i-icon icon="format-color-highlight" class="size-4" />
        </button>
    </a-popover>
</template>

<script setup>
import { defineComponent, ref } from 'vue'
import { classNames } from '@/mixins'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'TipTapHighlight',
})

const { t } = useI18n()

const { modelValue, editor, toolbar, isActive } = defineProps({
    modelValue: {
        type: Object,
        default: () => ({
            isIncludeHeader: false,
        }),
    },
    editor: {
        type: Object,
    },
    toolbar: {
        type: Object,
    },
    isActive: {
        type: Function,
    },
})

const emit = defineEmits(['update:modelValue'])

//  Popover state
const popoverState = ref(false)
//  Open table
const onOpen = () => {
    popoverState.value = true
}

// const { activeColor, closable } = defineProps({
//     activeColor: {
//         type: String,
//         default: '',
//     },
//     closable: {
//         type: Boolean,
//         default: false,
//     },
// })

const onSelect = (color) => {
    editor.chain().focus().toggleHighlight({ color: color.hex }).run()
}
//  Unset all color
const unSetColor = () => {
    editor.chain().focus().unsetHighlight().run()
}
// const isActive = (hex) => {
//     return activeColor === hex
// }

const colorSet = [
    [
        { label: 'color', rgb: 'rgb(0, 0, 0)', hex: '#000000' },
        { label: 'color', rgb: 'rgb(38, 38, 38)', hex: '#262626' },
        { label: 'color', rgb: 'rgb(89, 89, 89)', hex: '#595959' },
        { label: 'color', rgb: 'rgb(140, 140, 140)', hex: '#8c8c8c' },
        { label: 'color', rgb: 'rgb(191, 191, 191)', hex: '#bfbfbf' },
        { label: 'color', rgb: 'rgb(217, 217, 217)', hex: '#d9d9d9' },
        { label: 'color', rgb: 'rgb(233, 233, 233)', hex: '#e9e9e9' },
        { label: 'color', rgb: 'rgb(245, 245, 245)', hex: '#f5f5f5' },
        { label: 'color', rgb: 'rgb(250, 250, 250)', hex: '#fafafa', border: true },
        { label: 'color', rgb: 'rgb(255, 255, 255)', hex: '#ffffff', border: true },
    ],
    [
        { label: 'color', rgb: 'rgb(245, 34, 45)', hex: '#f5222d' },
        { label: 'color', rgb: 'rgb(250, 84, 28)', hex: '#fa541c' },
        { label: 'color', rgb: 'rgb(250, 140, 22)', hex: '#fa8c16' },
        { label: 'color', rgb: 'rgb(250, 219, 20)', hex: '#fadb14' },
        { label: 'color', rgb: 'rgb(82, 196, 26)', hex: '#52c41a' },
        { label: 'color', rgb: 'rgb(19, 194, 194)', hex: '#13c2c2' },
        { label: 'color', rgb: 'rgb(24, 144, 255)', hex: '#1890ff' },
        { label: 'color', rgb: 'rgb(47, 84, 235)', hex: '#2f54eb' },
        { label: 'color', rgb: 'rgb(114, 46, 209)', hex: '#722ed1' },
        { label: 'color', rgb: 'rgb(235, 47, 150)', hex: '#eb2f96' },
    ],
    [
        { label: 'color', rgb: 'rgb(255, 232, 230)', hex: '#ffe8e6' },
        { label: 'color', rgb: 'rgb(255, 236, 224)', hex: '#ffece0' },
        { label: 'color', rgb: 'rgb(255, 239, 209)', hex: '#ffefd1' },
        { label: 'color', rgb: 'rgb(255, 248, 189)', hex: '#fff8bd' },
        { label: 'color', rgb: 'rgb(228, 247, 210)', hex: '#e4f7d2' },
        { label: 'color', rgb: 'rgb(211, 245, 240)', hex: '#d3f5f0' },
        { label: 'color', rgb: 'rgb(212, 238, 252)', hex: '#d4eefc' },
        { label: 'color', rgb: 'rgb(222, 232, 252)', hex: '#dee8fc' },
        { label: 'color', rgb: 'rgb(239, 225, 250)', hex: '#efe1fa' },
        { label: 'color', rgb: 'rgb(250, 225, 235)', hex: '#fae1eb' },
    ],
    [
        { label: 'color', rgb: 'rgb(255, 163, 158)', hex: '#ffa39e' },
        { label: 'color', rgb: 'rgb(255, 187, 150)', hex: '#ffbb96' },
        { label: 'color', rgb: 'rgb(255, 213, 145)', hex: '#ffd591' },
        { label: 'color', rgb: 'rgb(255, 240, 143)', hex: '#fff08f' },
        { label: 'color', rgb: 'rgb(183, 235, 143)', hex: '#b7eb8f' },
        { label: 'color', rgb: 'rgb(135, 232, 222)', hex: '#87e8de' },
        { label: 'color', rgb: 'rgb(145, 213, 255)', hex: '#91d5ff' },
        { label: 'color', rgb: 'rgb(173, 198, 255)', hex: '#adc6ff' },
        { label: 'color', rgb: 'rgb(211, 173, 247)', hex: '#d3adf7' },
        { label: 'color', rgb: 'rgb(255, 173, 210)', hex: '#ffadd2' },
    ],
    [
        { label: 'color', rgb: 'rgb(255, 77, 79)', hex: '#ff4d4f' },
        { label: 'color', rgb: 'rgb(255, 122, 69)', hex: '#ff7a45' },
        { label: 'color', rgb: 'rgb(255, 169, 64)', hex: '#ffa940' },
        { label: 'color', rgb: 'rgb(255, 236, 61)', hex: '#ffec3d' },
        { label: 'color', rgb: 'rgb(115, 209, 61)', hex: '#73d13d' },
        { label: 'color', rgb: 'rgb(54, 207, 201)', hex: '#36cfc9' },
        { label: 'color', rgb: 'rgb(64, 169, 255)', hex: '#40a9ff' },
        { label: 'color', rgb: 'rgb(89, 126, 247)', hex: '#597ef7' },
        { label: 'color', rgb: 'rgb(146, 84, 222)', hex: '#9254de' },
        { label: 'color', rgb: 'rgb(247, 89, 171)', hex: '#f759ab' },
    ],
    [
        { label: 'color', rgb: 'rgb(207, 19, 34)', hex: '#cf1322' },
        { label: 'color', rgb: 'rgb(212, 56, 13)', hex: '#d4380d' },
        { label: 'color', rgb: 'rgb(212, 107, 8)', hex: '#d46b08' },
        { label: 'color', rgb: 'rgb(212, 177, 6)', hex: '#d4b106' },
        { label: 'color', rgb: 'rgb(56, 158, 13)', hex: '#389e0d' },
        { label: 'color', rgb: 'rgb(8, 151, 156)', hex: '#08979c' },
        { label: 'color', rgb: 'rgb(9, 109, 217)', hex: '#096dd9' },
        { label: 'color', rgb: 'rgb(29, 57, 196)', hex: '#1d39c4' },
        { label: 'color', rgb: 'rgb(83, 29, 171)', hex: '#531dab' },
        { label: 'color', rgb: 'rgb(196, 29, 127)', hex: '#c41d7f' },
    ],
    [
        { label: 'color', rgb: 'rgb(130, 0, 20)', hex: '#820014' },
        { label: 'color', rgb: 'rgb(135, 20, 0)', hex: '#871400' },
        { label: 'color', rgb: 'rgb(135, 56, 0)', hex: '#873800' },
        { label: 'color', rgb: 'rgb(97, 71, 0)', hex: '#614700' },
        { label: 'color', rgb: 'rgb(19, 82, 0)', hex: '#135200' },
        { label: 'color', rgb: 'rgb(0, 71, 79)', hex: '#00474f' },
        { label: 'color', rgb: 'rgb(0, 58, 140)', hex: '#003a8c' },
        { label: 'color', rgb: 'rgb(6, 17, 120)', hex: '#061178' },
        { label: 'color', rgb: 'rgb(34, 7, 94)', hex: '#22075e' },
        { label: 'color', rgb: 'rgb(120, 6, 80)', hex: '#780650' },
    ],
]
</script>
