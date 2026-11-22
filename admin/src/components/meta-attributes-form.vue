<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Metadata attributes form, extracted from the place create/update pages.
    Renders the meta hierarchy Class → Group → Attribute: attributes attached
    directly to the class keep the old flat list; attributes attached to a
    display group (meta_classes type=group) render under the group heading.
    Values still bind to form.meta[attribute.name].value — same contract as
    before, purely a visual regrouping.
-->
<template>
    <div v-for="meta in classes || []" :key="meta.id" class="mb-2">
        <template v-if="meta && meta.id">
            <div v-if="(classes || []).length > 1">
                <h5 class="uppercase">{{ meta.name }}</h5>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                <template v-for="attribute in meta.options || []" :key="attribute.id">
                    <meta-attribute-input
                        v-if="attribute && attribute.is_filter && form.meta[attribute.name]"
                        v-model="form.meta[attribute.name].value"
                        :attribute="attribute"
                        :name="['meta', attribute.name, 'value']"
                    />
                </template>
            </div>

            <div v-for="group in visibleGroups(meta)" :key="group.id" class="mt-1 mb-3">
                <h5 class="uppercase text-xs tracking-widest text-gray-500 mb-2">
                    {{ group.name }}
                </h5>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                    <template v-for="attribute in group.options || []" :key="attribute.id">
                        <meta-attribute-input
                            v-if="attribute && attribute.is_filter && form.meta[attribute.name]"
                            v-model="form.meta[attribute.name].value"
                            :attribute="attribute"
                            :name="['meta', attribute.name, 'value']"
                        />
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { defineComponent } from 'vue'

defineComponent({ name: 'MetaAttributesForm' })

defineProps({
    /** Meta class payload from useMetaClasses (classes with group + options). */
    classes: { type: Array, default: () => [] },
    /** The page's reactive form object (reads/writes form.meta[name].value). */
    form: { type: Object, required: true },
})

const visibleGroups = (meta) =>
    (meta.group || []).filter((group) =>
        (group.options || []).some((option) => option && option.is_filter)
    )
</script>
