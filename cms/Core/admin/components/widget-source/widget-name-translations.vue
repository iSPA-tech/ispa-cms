<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    The Vietnamese and English text of a widget or design name (`kind="name"`) or of a widget description
    (`kind="description"`), next to the original one the form keeps. `v-model` is the `name_translations` /
    `description_translations` value of the API: `{vi?: string, en?: string}` or null. An empty field means "use the
    English text, else the original one"; the save payload drops blanks.
-->
<template>
    <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
        <a-form-item
            v-for="locale in WIDGET_NAME_LOCALES"
            :key="locale"
            :label="$t(`text.widget_library.${kind}_${locale}`)"
        >
            <component
                :is="kind === 'description' ? 'a-textarea' : 'a-input'"
                :data-test="`${kind}-${locale}`"
                :maxlength="kind === 'description' ? 500 : 255"
                :placeholder="$t(`text.widget_library.${kind}_${locale}`)"
                :rows="kind === 'description' ? 5 : undefined"
                :value="modelValue?.[locale] ?? ''"
                @update:value="update(locale, $event)"
            />
        </a-form-item>
    </div>
</template>

<script setup>
import { WIDGET_NAME_LOCALES } from '@/libs/widget-names'

defineOptions({ name: 'WidgetNameTranslations' })

const props = defineProps({
    // `{vi?: string, en?: string}` or null, as the API sends and accepts it.
    modelValue: { type: Object, default: null },
    // 'name' | 'description': which text the two fields hold (label and field type).
    kind: { type: String, default: 'name' },
})

const emit = defineEmits(['update:modelValue'])

const update = (locale, text) => emit('update:modelValue', { ...(props.modelValue ?? {}), [locale]: text })
</script>
