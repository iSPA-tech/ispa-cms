<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->
<template>
    <a-popover
        v-model:open="open"
        trigger="click"
        placement="bottomLeft"
        :overlay-inner-style="{ padding: '12px' }"
    >
        <template #content>
            <div class="w-96">
                <a-input
                    v-model:value="search"
                    :placeholder="resolvedSearchPlaceholder"
                    :aria-label="resolvedSearchPlaceholder"
                    allow-clear
                    class="mb-2"
                />
                <div class="grid grid-cols-10 gap-1 max-h-72 overflow-y-auto pr-1">
                    <button
                        v-for="name in filtered"
                        :key="name"
                        :title="name"
                        type="button"
                        class="p-1.5 rounded-sm flex items-center justify-center transition-colors hover:bg-cyan-100 hover:text-cyan-700"
                        :class="
                            name === modelValue
                                ? 'bg-cyan-100 text-cyan-600 ring-1 ring-cyan-400'
                                : 'text-slate-600'
                        "
                        @click="select(name)"
                    >
                        <i-icon :icon="name" class="size-5" />
                    </button>
                    <div
                        v-if="!filtered.length"
                        class="col-span-10 text-center text-xs text-slate-400 py-6"
                    >
                        {{ resolvedEmptyText }}
                    </div>
                </div>
            </div>
        </template>
        <a-input
            :value="modelValue"
            :placeholder="resolvedPlaceholder"
            allow-clear
            @update:value="emit('update:modelValue', $event || '')"
        >
            <template #prefix>
                <i-icon v-if="modelValue" :icon="modelValue" class="size-4 text-slate-500" />
                <i-icon v-else icon="magnify" class="size-4 text-slate-300" />
            </template>
        </a-input>
    </a-popover>
</template>

<script setup>
import { computed, defineComponent, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { iconAliases, iconNames } from '@/config/icon-maps'
import IIcon from '@/components/i-icon.vue'

defineComponent({ name: 'IconPicker' })

const props = defineProps({
    modelValue: { type: String, default: '' },
    // Empty defaults fall back to the translated texts below, so the labels follow the admin language.
    placeholder: { type: String, default: '' },
    searchPlaceholder: { type: String, default: '' },
    emptyText: { type: String, default: '' },
})

const { t } = useI18n()

const resolvedPlaceholder = computed(() => props.placeholder || t('text.ui.icon-picker.placeholder'))
const resolvedSearchPlaceholder = computed(
    () => props.searchPlaceholder || t('text.ui.icon-picker.search')
)
const resolvedEmptyText = computed(() => props.emptyText || t('text.ui.icon-picker.empty'))

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const search = ref('')

//  Fold Vietnamese diacritics so "truong hoc" matches "trường học" (and vice versa).
const fold = (str) =>
    str
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')

//  Pre-folded alias index: icon name -> searchable text (english name + vietnamese aliases).
const searchIndex = iconNames.map((name) => ({
    name,
    haystack: fold(`${name} ${iconAliases[name] || ''}`),
}))

const filtered = computed(() => {
    const q = fold(search.value.trim())
    if (!q) return iconNames

    return searchIndex.filter((entry) => entry.haystack.includes(q)).map((entry) => entry.name)
})

const select = (name) => {
    emit('update:modelValue', name)
    open.value = false
}
</script>
