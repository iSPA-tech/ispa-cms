<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div>
        <a-form-item
            :name="name"
            :rules="[
                {
                    required: required,
                    trigger: 'blur',
                    message: $t('validation.required', {
                        a: $t('validation.attributes.tags'),
                    }),
                },
            ]"
            class="more_tools mb-0"
        >
            <template #label>
                <div class="flex items-center flex-1">
                    <span class="flex-1">{{ $t('validation.attributes.tags') }}</span>
                    <a-button
                        v-if="showCreate && rule('tag.viewAny')"
                        class="btn-success plain text-xs"
                        size="small"
                        @click="openTagsModal"
                    >
                        {{ $t('nav.b.add') }} {{ $t('validation.attributes.tags') }}
                    </a-button>
                </div>
            </template>

            <a-select
                v-model:value="innerForm[innerName]"
                :field-names="{ label: 'name', value: 'id' }"
                :filter-option="false"
                :not-found-content="loadingState ? undefined : null"
                :options="searchTags || []"
                :placeholder="$t('text.select')"
                allow-clear
                change-on-select
                class="w-full"
                mode="multiple"
                show-search
                @change="handleChange"
                @search="fetchTagDebounce"
            />
        </a-form-item>

        <a-modal
            v-model:open="tagModalState"
            :title="$t('validation.attributes.tags')"
            class="modal-fullscreen"
            fullscreen
            width="100%"
            :footer="null"
        >
            <tag-manage :visible="tagModalState"></tag-manage>
        </a-modal>
    </div>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import { useTags } from '@/hooks'
import { searchFilterTags } from '@/libs'
import { iAlert, rule } from '@/mixins'
import { debounce } from 'lodash-es'

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => {},
    },
    required: { type: Boolean, default: false },
    showCreate: { type: Boolean, default: true },
    name: { type: [String, Array], default: 'tag_id' },
})

const emit = defineEmits(['update:modelValue', 'change-last'])

defineComponent({
    name: 'InputTag',
})

const { data: tags, refetch } = useTags()
const tagModalState = ref(false)
const innerForm = ref(props.modelValue)
const innerName = ref(null)
//  Open modal
const openTagsModal = () => {
    tagModalState.value = true
}
//  Search item by id in reservation tag list
const searchItem = (data = [], id = 0) => {
    let item = {}
    //  Find item in data by id, if found any => return immediate
    //  if not found, find in the children list
    data.some((i) => {
        if (i.id === id) {
            item = i
            return true
        }
    })

    return item
}

//  Handle change event
const handleChange = (val) => {
    //  Get last item in val then find it in tag list, send this item to parent
    const item = searchItem(tag.value?.data, val[val.length - 1])

    if (item) {
        emit('change-last', item)
    }
}
//  Process to update query and search
const searchTags = ref([])
const loadingState = ref(false)
const fetchTag = async (value, type = 'keywords') => {
    const params = {
        [type]: value,
    }
    loadingState.value = true
    //  Fetch user list
    await searchFilterTags(params)
        .then((res) => {
            searchTags.value = res.data
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            loadingState.value = false
        })
}
const fetchTagDebounce = debounce(fetchTag, 500)

watch(
    () => tagModalState.value,
    (val) => {
        if (!val) {
            refetch()
        }
    }
)

watch(
    [() => props.modelValue, () => props.name],
    ([val, name]) => {
        if (val !== innerForm.value) {
            innerForm.value = val
        }
        if (Array.isArray(name)) {
            innerName.value = name
        } else {
            innerName.value = name
        }
    },
    { immediate: true, deep: true }
)

watch(
    () => innerForm.value,
    (val) => {
        if (val !== props.modelValue) {
            emit('update:modelValue', val)
        }
    }
)

watch(
    () => tags,
    (val) => {
        if (val.data) {
            searchTags.value = val.data
        }
    }
)

onMounted(() => {
    fetchTag(30, 'pageSize')
})
</script>
