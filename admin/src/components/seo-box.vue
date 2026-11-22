<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="i-box">
        <div class="flex items-center space-x-2">
            <div class="flex-1">
                <h3 class="font-medium text-blk-950">{{ $t('text.e.desc-seo') }}</h3>
            </div>

            <div>
                <a-tooltip v-if="!seoState" :title="$t('nav.b.add_seo')">
                    <span
                        class="rounded-sm bg-white hover:bg-blk-100 transition-all size-6 p-1"
                        role="button"
                        @click="seoState = !seoState"
                    >
                        <i-icon
                            class="size-4 text-slate-600 hover:text-slate-800"
                            icon="pencil-outline"
                        />
                    </span>
                </a-tooltip>
            </div>
        </div>
        <!--        SEO layout preview in search engine -->
        <div v-if="innerForm.slugs && innerForm.slugs.slug && innerForm.slugs.slug.length > 0">
            <div class="p-3 border bg-gray-50 rounded-lg shadow-sm">
                <h3 class="text-gray-700">{{ webTitle }}</h3>
                <seo-url-parser :url="genUrl(innerForm.slugs.slug)" />
                <h3 class="text-lg text-blue-500">
                    {{ innerForm.seos[0].title || innerForm.name }}
                </h3>
                <p class="text-sm text-gray-600">
                    {{ innerForm.seos[0].description || innerForm.description }}
                </p>
            </div>
        </div>
        <p v-else class="text-slate-500 text-sm">
            {{ $t('text.form.seo_description').replace(':name', $t('nav.a.posts')) }}
        </p>

        <div
            v-if="seoState && !['notification', 'admission'].includes(innerForm.type)"
            class="border-t pt-3 mt-3"
        >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                <i-seo
                    v-model="innerForm.seos"
                    :language-id="innerForm.language_id"
                    module="post"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { defineComponent, inject, ref, watch } from 'vue'

defineComponent({
    name: 'SeoBox',
})

const globalSettings = inject('globalSettings', {})
const seoState = ref(false)
const emits = defineEmits(['update:modelValue'])
const props = defineProps({
    modelValue: { type: Object, required: true },
})
//  Get from post.vue
const postMedia = inject('postMedia', [])

const innerForm = ref(props.modelValue)

const currentTypeObject = ref({ prefix: {} })
const categoriesList = inject('categoriesList', [])
const categoryObject = ref({ slug: '' })
const languages = inject('languages', {})
const webTitle = ref('')

const genUrl = (slug) => {
    const getCurrentLanguage = languages.value?.data?.find(
        (lang) => lang.id === innerForm.value.language_id
    )
    const prefix = currentTypeObject.value.prefix[getCurrentLanguage?.path ?? 'vi'] || ''
    // If postMedia is not empty, use the first item as the base URL

    return `${prefix}/${categoryObject ? categoryObject.value.slug + '/' : ''}${slug}`
}

const getCurrentSeoObjectByLanguage = (languageId = 0) => {
    return (
        innerForm.value.seos.find((seo) => seo.language_id === languageId) || {
            title: '',
            hashtag: '',
            description: '',
            language_id: languageId,
        }
    )
}

const searchChildrenCategory = (children = [], childrenId = 0) => {
    return children.find((child) => child.id === childrenId) || null
}

watch(
    [() => globalSettings.value, () => languages.value, () => innerForm.value],
    ([settings, languages, formValue]) => {
        if (settings && languages) {
            const currentLanguage = languages?.data?.find(
                (lang) => lang.id === formValue.language_id
            )
            if (!currentLanguage) {
                return
            }

            const setting = (settings?.data || []).find(
                (st) =>
                    st.group_name === 'basic' &&
                    st.name === 'title' &&
                    st.language_id === currentLanguage.id
            )

            webTitle.value = setting ? setting.value : 'N/a'
        }
    },
    {
        immediate: true,
        deep: true,
    }
)

watch(
    () => props.modelValue,
    (newVal) => {
        innerForm.value = newVal
        currentTypeObject.value = postMedia().find((item) => item.value === newVal.type) || {
            prefix: {},
        }
        if (!newVal.seos || newVal.seos.length === 0) {
            innerForm.value = {
                ...innerForm.value,
                seos: [getCurrentSeoObjectByLanguage(newVal.language_id)],
            }
        }
    },
    { immediate: true, deep: true }
)

watch(
    [() => props.modelValue, () => categoriesList.value],
    ([model, cateList]) => {
        if (
            model &&
            model.category_id &&
            model.category_id.length > 0 &&
            cateList &&
            cateList.length > 0
        ) {
            // Get the first array of category IDs if it's an array, or the single ID if it's not
            const categoryArray = Array.isArray(model.category_id)
                ? model.category_id[0]
                : model.category_id
            // Get the last category ID if it's an array, or the single ID if it's not
            // In categoryArray, first item is the parent category, next item is an item inside of parent.children,
            // next item is an item inside of parent.children.children, etc. so we need to find the last item in the array
            // Find the category object in cateList that matches the last category ID
            // If latsCategoryId is not a number, return the first category in cateList
            const slugs = []
            let rootCategory = {}
            let secondCategory = {}
            let thirdCategory = {}
            let lastCategory = {}

            categoryArray.forEach((categoryId, index) => {
                if (index === 0) {
                    rootCategory = searchChildrenCategory(cateList, categoryId)
                    slugs.push(rootCategory.slug)
                } else if (index === 1) {
                    secondCategory = searchChildrenCategory(rootCategory.children, categoryId)
                    slugs.push(secondCategory.slug)
                } else if (index === 2) {
                    thirdCategory = searchChildrenCategory(secondCategory.children, categoryId)
                    slugs.push(thirdCategory.slug)
                } else if (index === 3) {
                    lastCategory = searchChildrenCategory(thirdCategory.children, categoryId)
                    slugs.push(lastCategory.slug)
                } else {
                    // Do nothing for more than 3 categories
                    console.warn('More than 3 categories are not supported')
                }
            })
            categoryObject.value = {
                slug: slugs.join('/'),
            }
        } else {
            categoryObject.value = {
                slug: '',
            }
        }
    },
    { immediate: true, deep: true }
)
</script>
