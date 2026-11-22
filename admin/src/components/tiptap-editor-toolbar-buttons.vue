<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <template
        v-for="(toolbar, toolbarIndex) in toolbars"
        :key="`${toolbar.name}-${toolbar.icon || toolbarIndex}`"
    >
        <a-tooltip v-if="toolbar.name !== 'break'">
            <template #title>{{ toolbar.title }}</template>
            <button
                v-if="
                    ![
                        'break',
                        'heading-select',
                        'table-select',
                        'color-select',
                        'highlight-select',
                        'link-select',
                        'media-image-select',
                        'math-block-select',
                        'font-family-select',
                        'font-size-select',
                    ].includes(toolbar.name)
                "
                :class="
                    classNames(
                        toolbar.isActive() ? 'bg-cyan-600 text-white' : '',
                        'hover:bg-gray-200 hover:text-gray-900 text-gray-600 text-center text-base rounded-sm',
                        'w-6 h-6 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center'
                    )
                "
                :disabled="toolbar.name === 'deleteLink' && !isActive('link')"
                type="button"
                @click="toolbar.action()"
            >
                <i-icon :icon="toolbar.icon" class="size-4" />
            </button>
            <a-select
                v-else-if="toolbar.name === 'heading-select'"
                v-model:value="innerValue.heading"
                :placeholder="t('text.form.heading-select')"
                class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                size="small"
                @change="(value) => editor.chain().focus().toggleHeading({ level: value }).run()"
            >
                <a-select-option
                    v-for="id in range(1, 7)"
                    :key="id"
                    :selected="isActive('heading', { level: id })"
                    :value="id"
                >
                    {{ t('text.editor.heading_level', { level: id }) }}
                </a-select-option>
            </a-select>
            <template v-else-if="toolbar.name === 'table-select'">
                <tip-tap-table :editor="editor" :isActive="isActive" :toolbar="toolbar" />
            </template>
            <template v-else-if="toolbar.name === 'color-select'">
                <tip-tap-color :editor="editor" :isActive="isActive" :toolbar="toolbar" />
            </template>
            <template v-else-if="toolbar.name === 'highlight-select'">
                <tip-tap-highlight :editor="editor" :isActive="isActive" :toolbar="toolbar" />
            </template>
            <template v-else-if="toolbar.name === 'link-select'">
                <tip-tap-link :editor="editor" :isActive="isActive" :toolbar="toolbar" />
            </template>
            <template v-else-if="toolbar.name === 'media-image-select'">
                <tip-tap-media-image :editor="editor" :isActive="isActive" :toolbar="toolbar" />
            </template>
            <template v-else-if="toolbar.name === 'math-block-select'">
                <tip-tap-math :editor="editor" :isActive="isActive" :toolbar="toolbar" />
            </template>
            <template v-else-if="toolbar.name === 'font-family-select'">
                <a-select
                    v-model:value="innerValue.fontFamily"
                    :placeholder="t('text.form.font-family-select')"
                    class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                    size="small"
                    @change="(value) => setFontFamily(value)"
                >
                    <a-select-option
                        v-for="item in fontFamilies"
                        :key="item.id"
                        :selected="editor.isActive('textStyle', { fontFamily: item.id })"
                        :value="item.id"
                    >
                        {{ item.label }}
                    </a-select-option>
                </a-select>
            </template>
            <template v-else-if="toolbar.name === 'font-size-select'">
                <a-select
                    v-model:value="innerValue.fontSize"
                    :placeholder="t('text.form.font-size-select')"
                    class="w-28 max-w-28 text-xs placeholder:text-xs *:text-xs"
                    size="small"
                    @change="(value) => setFontSize(value)"
                >
                    <a-select-option
                        v-for="item in fontSizes"
                        :key="item.id"
                        :selected="editor.isActive('textStyle', { fontSize: item.id })"
                        :value="item.id"
                    >
                        <span :style="{ 'font-size': item.id }">{{ item.label }}</span>
                    </a-select-option>
                </a-select>
            </template>
        </a-tooltip>
        <span v-else class="pl-0.5 ml-0.5 border-l border-gray-400 h-5 mt-0.5" />
    </template>
</template>

<script setup>
import { computed, defineComponent, ref } from 'vue'
import { classNames } from '@/mixins'
import { range } from 'lodash-es'
import TipTapTable from '@/components/tiptap-table.vue'
import TipTapLink from '@/components/tiptap-link.vue'
import TipTapHighlight from '@/components/tiptap-highlight.vue'
import TipTapColor from '@/components/tiptap-color.vue'
import TipTapMediaImage from '@/components/tiptap-media-image.vue'
import TipTapMath from '@/components/tiptap-math.vue'
import { useI18n } from 'vue-i18n'

defineComponent({
    name: 'TipTapEditorToolbarButtons',
})

const { t } = useI18n()

const { editor, toolbars, currentEditorActive } = defineProps({
    editor: {
        type: Object,
    },
    toolbars: {
        type: Array,
    },
    currentEditorActive: {
        type: Object,
    },
})

const innerValue = ref(currentEditorActive)

const isActive = (type, options = {}) => {
    return editor.isActive(type, options)
}

const fontFamilies = computed(() => [
    {
        id: 'unset',
        label: t('text.editor.font_family.unset'),
    },
    {
        id: 'Arial',
        label: 'Arial',
    },
    {
        id: 'Arial Black',
        label: t('text.editor.font_family.arial_black'),
    },
    {
        id: 'Comic Sans MS',
        label: t('text.editor.font_family.comic_sans_ms'),
    },
    {
        id: 'Courier New',
        label: t('text.editor.font_family.courier_new'),
    },
    {
        id: 'Georgia',
        label: 'Georgia',
    },
    {
        id: 'Impact',
        label: 'Impact',
    },
    {
        id: 'Lucida Console',
        label: t('text.editor.font_family.lucida_console'),
    },
    {
        id: 'Lucida Sans Unicode',
        label: t('text.editor.font_family.lucida_sans_unicode'),
    },
    {
        id: 'Palatino Linotype',
        label: t('text.editor.font_family.palatino_linotype'),
    },
    {
        id: 'Tahoma',
        label: 'Tahoma',
    },
    {
        id: 'Times New Roman',
        label: t('text.editor.font_family.times_new_roman'),
    },
    {
        id: 'Trebuchet MS',
        label: t('text.editor.font_family.trebuchet_ms'),
    },
    {
        id: 'Verdana',
        label: 'Verdana',
    },
])

const fontSizes = computed(() => [
    { id: '2.5rem', label: t('text.editor.font_size.bigger'), labelSize: '1.75rem' },
    { id: '2rem', label: t('text.editor.font_size.big'), labelSize: '1.5rem' },
    { id: '1.5rem', label: t('text.editor.font_size.medium'), labelSize: '1.25rem' },
    { id: 'unset', label: t('text.editor.font_size.normal'), labelSize: '1rem' },
    { id: '0.8125rem', label: t('text.editor.font_size.small'), labelSize: '0.8125rem' },
    { id: '0.75rem', label: t('text.editor.font_size.smaller'), labelSize: '0.75rem' },
])

const setFontFamily = (value) => {
    if (value === 'unset') {
        editor.chain().focus().unsetFontFamily().run()
    } else {
        editor.chain().focus().setFontFamily(value).run()
    }
}

const setFontSize = (value) => {
    if (value === 'unset') {
        editor.chain().focus().unsetFontSize().run()
    } else {
        editor.chain().focus().setFontSize(value).run()
    }
}
</script>
