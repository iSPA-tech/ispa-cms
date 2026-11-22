<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-popover v-model:open="popoverState" placement="left" trigger="click" @openChange="onChange">
        <template #content>
            <section class="v-table-grid">
                <header>
                    <a-checkbox v-model:checked="innerValue.isIncludeHeader" class="mr-2">
                        {{ t('text.form.first-table-header') }}
                    </a-checkbox>
                </header>
                <div
                    v-for="(row, i) in defaultSelected.rows"
                    :key="`row-${i}`"
                    class="v-row justify-start cursor-pointer"
                >
                    <div v-for="(col, j) in defaultSelected.columns" :key="`col-${j}`">
                        <div
                            :class="{
                                selected:
                                    row <= defaultSelected.selectedRows &&
                                    col <= defaultSelected.selectedColumns,
                            }"
                            class="cell"
                            @click="onSelected()"
                            @mouseover="select(row, col)"
                        />
                    </div>
                </div>

                <footer class="row justify-center q-pt-md">
                    {{ defaultSelected.selectedRows }} x {{ defaultSelected.selectedColumns }}
                </footer>
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
            <i-icon icon="table" class="size-4" />
        </button>
    </a-popover>
</template>

<script setup>
import { defineComponent, ref, watch } from 'vue'
import { classNames } from '@/mixins'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'TipTapTable',
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

const innerValue = ref(modelValue)

//  Popover state
const popoverState = ref(false)

const defaultSelected = ref({
    rows: 5,
    columns: 5,
    selectedRows: 2,
    selectedColumns: 2,
})

const onChange = () => {
    if (!popoverState.value) {
        onHide()
    }
}
//  Open table
const onOpen = () => {
    popoverState.value = true
}

const onHide = () => {
    defaultSelected.value.rows = 5
    defaultSelected.value.columns = 5
    defaultSelected.value.selectedRows = 2
    defaultSelected.value.selectedColumns = 2
}
const select = (row, col) => {
    defaultSelected.value.selectedRows = row
    defaultSelected.value.selectedColumns = col

    if (defaultSelected.value.rows === row && defaultSelected.value.rows < 10) {
        defaultSelected.value.rows += 1
    }
    if (defaultSelected.value.columns === col && defaultSelected.value.columns < 10) {
        defaultSelected.value.columns += 1
    }
}
const onSelected = () => {
    editor
        .chain()
        .focus()
        .insertTable({
            rows: defaultSelected.value.selectedRows,
            cols: defaultSelected.value.selectedColumns,
            withHeaderRow: innerValue.value.isIncludeHeader,
        })
        .run()
}

watch(
    () => innerValue,
    (val) => {
        emit('update:modelValue', val)
    }
)
</script>

<!--<style lang="stylus">-->

<!--</style>-->
