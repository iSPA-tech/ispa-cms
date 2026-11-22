<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-select
        v-model:value="innerValue"
        :filter-option="false"
        :loading="loading"
        :options="options"
        :default-active-first-option="false"
        :placeholder="placeholder || (allowCreate ? tt('text.user-tag.placeholder') : tt('text.user-tag.filter_placeholder'))"
        :size="size"
        allow-clear
        class="w-full"
        mode="multiple"
        show-search
        @change="onChange"
        @search="onSearch"
    />
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { debounce } from 'lodash-es'
import { useI18n } from 'vue-i18n'
import { createUserTag, searchUserTags } from '@/libs'
import { makeTagText, userTagNames } from '@/components/user-tag-text'
import { iAlert } from '@/mixins'

/** Multi-select of user tags by uuid with remote search. Filter use keeps `allowCreate` off; only the apply dialog of "Gắn tag" turns it on. */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    allowCreate: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    size: { type: String, default: undefined },
})
const emit = defineEmits(['update:modelValue'])
const { t, te } = useI18n()
const tt = makeTagText({ t, te })
const CREATE = '__create__:'
const known = ref([])
const search = ref('')
const loading = ref(false)
const innerValue = ref([...(props.modelValue || [])])

watch(() => props.modelValue, (value) => { innerValue.value = [...(value || [])] })

const merge = (rows) => {
    const byUuid = new Map(known.value.map((tag) => [tag.uuid, tag]))
    rows.forEach((tag) => { byUuid.set(tag.uuid, tag); userTagNames.set(tag.uuid, tag.name) })
    known.value = [...byUuid.values()]
}
const load = async (q = '') => {
    loading.value = true
    try {
        merge(await searchUserTags(q))
    } catch (error) {
        iAlert(error, true)
    } finally {
        loading.value = false
    }
}
const loadDebounced = debounce(load, 400)
const onSearch = (q) => {
    search.value = q ?? ''
    loadDebounced(search.value)
}
const options = computed(() => {
    const list = known.value.map((tag) => ({ label: tag.name, value: tag.uuid }))
    // A prefilled value outside the loaded page still shows its name, never its uuid.
    innerValue.value.forEach((uuid) => {
        if (!known.value.some((tag) => tag.uuid === uuid) && userTagNames.has(uuid)) list.push({ label: userTagNames.get(uuid), value: uuid })
    })
    const typed = search.value.trim()
    // The create option exists only for explicitly typed text, and only the operator's click or arrow+Enter can pick it.
    if (props.allowCreate && typed && !known.value.some((tag) => tag.name.toLowerCase() === typed.toLowerCase())) {
        list.push({ label: tt('text.user-tag.create_option', { name: typed }), value: CREATE + typed })
    }
    return list
})
const onChange = async (values) => {
    const next = []
    for (const value of values || []) {
        if (typeof value === 'string' && value.startsWith(CREATE)) {
            const name = value.slice(CREATE.length).trim()
            if (!name) continue
            try {
                const tag = await createUserTag(name)
                merge([tag])
                next.push(tag.uuid)
            } catch (error) {
                iAlert(error, true)
            }
        } else {
            next.push(value)
        }
    }
    innerValue.value = next
    search.value = ''
    emit('update:modelValue', next)
}
onMounted(() => load(''))
</script>
