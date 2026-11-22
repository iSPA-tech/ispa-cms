<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="xl:flex-1 w-full">
        <router-view />
    </div>
</template>

<script setup>
import { computed, defineComponent, provide, ref, h, inject, nextTick, toRaw } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
    can,
    getSettingValue,
    iAlert,
    objectAssignOnlyByKeyHasValue,
    runSubmitOnce,
} from '@/mixins'
import ajax from '@/ajax'
import { usePostDashboard } from '@/hooks'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import { cloneDeep, isEqual } from 'lodash-es'
import { useAppRuntime } from '@/runtime'

defineComponent({
    name: 'Post',
})

const { globalSettings } = useAppRuntime()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

//  Get type by the last element of route name
const getTypeByRouteName = (routeName) => {
    const type = routeName.split('-').pop()

    return type === 'manage' ? 'post' : type
}

/** Form config */
const btnState = ref(false)
provide('btnState', btnState)
const formDefault = () => {
    return {
        id: 0,
        uid: null,
        name: '',
        description: '',
        content: [{ type: 'text', value: null }],
        hashtag: [],
        type: 'post',
        video_url: null,
        parent_id: null,
        language_id: null,
        user_id: null,
        status: 'status_published',
        published_at: null,
        attachments: [],
        slugs: { slug: null },
        categories: [],
        category_id: [],
        images: [],
        tag_id: [],
        review_pipeline: null,
        config: {
            featured: false,
            is_full_page: false,
            requiredLogin: false,
            roles: [],
            showApi: false,
            apiList: ['universal'],
            allowComment: false,
            bookAuthor: null,
            bookReleaseDate: null,
            recommended: false,
        },
        seos: [],
    }
}
provide('formDefault', formDefault)
const checkFormChanged = (formData = {}, formDefaultData = {}, comparedFields = []) => {
    if (Object.keys(formData).length === 0 && Object.keys(formDefaultData).length === 0) {
        return false
    }

    //  If comparedFields is not empty, filter formData and formDefaultData
    if (comparedFields.length > 0) {
        formData = Object.fromEntries(
            Object.entries(formData).filter(([key]) => comparedFields.includes(key))
        )
        formDefaultData = Object.fromEntries(
            Object.entries(formDefaultData).filter(([key]) => comparedFields.includes(key))
        )
    }

    return !isEqual(formData, formDefaultData)
}
provide('checkFormChanged', checkFormChanged)
const form = ref(formDefault())
provide('form', form)
const formRef = ref(null)
provide('formRef', formRef)
const resetForm = () => {
    form.value = formDefault()
}
provide('resetForm', resetForm)
const setForm = (val = {}) => {
    resetForm()
    nextTick(() => {
        form.value = {
            ...formDefault(),
            ...val,
            published_at: val.published_at ? dayjs(val.published_at) : null,
        }
    })

    if (Object.keys(form.value.config).length === 0) {
        form.value.config = formDefault().config
    }
    if (!val.slugs || Object.keys(val.slugs).length === 0) {
        form.value.slugs = { slug: null }
    }
    if (!val.seos || val.seos.length === 0) {
        form.value.seos = []
    }

    //  Set attach file
    if (can('post', 'attach', 'document')) {
        form.value.attachments = val.attachments || []
    }
}
provide('setForm', setForm)
const normalizePublishedAt = (value) => {
    if (!value) {
        return null
    }

    if (dayjs.isDayjs(value)) {
        return value.isValid() ? value.format('YYYY-MM-DD HH:mm:ss') : null
    }

    return value
}
const submitForm = (data) => {
    const contentSource = Array.isArray(form.value?.content)
        ? cloneDeep(toRaw(form.value.content))
        : []
    const value = objectAssignOnlyByKeyHasValue(cloneDeep(toRaw(form.value)), data)
    value.content = contentSource
    value.published_at = normalizePublishedAt(value.published_at)

    //  check type audio and file selected before submit
    if (['audio', 'document'].includes(value.type) && value.attachments.length < 1) {
        iAlert(t('validation.required', { a: t('text.files') }), true)
        return
    }

    return runSubmitOnce(btnState, () => {
        const call = value.id > 0 ? ajax.put(`/post/${value.id}`, value) : ajax.post(`/post`, value)

        return call
            .then((res) => {
                if (res.data.status === 'success') {
                    if (value.id < 1) {
                        resetForm()
                    }
                    setTimeout(() => {
                        //  Get route name by post type
                        const routeName = `post-${value.type}`
                        router.push({
                            name: routeName,
                            query: {
                                ...route.query,
                                type: value.type,
                            },
                        })
                    }, 3000)
                }
                if (res.data.message) {
                    iAlert(res.data)
                }
            })
            .catch((err) => {
                iAlert(err, true)
            })
    })
}
provide('submitForm', submitForm)
const compareFields = [
    'name',
    'content',
    'description',
    'category_id',
    'status',
    'published_at',
    'type',
    'video_url',
    'language_id',
    'images',
    'review_pipeline',
]
provide('compareFields', compareFields)

const getLimitImageByPostType = (type) => {
    if (type === 'page') {
        return 1
    }

    return getSettingValue(globalSettings, 'upload.max_image_count.post', 1)
}
provide('getLimitImageByPostType', getLimitImageByPostType)

const {
    data: postDashboard,
    isLoading: isPostDashboardLoading,
    isFetching: isPostDashboardFetching,
    error: postDashboardError,
    refetch: refetchPostDashboard,
    dataUpdatedAt: postDashboardUpdatedAt,
} = usePostDashboard(
    {},
    {
        keepPreviousData: true,
        onError: (error) => {
            const message = error?.message || t('text.post.dashboard_failed')
            iAlert(message, true)
        },
    }
)

const isLoading = computed(() => isPostDashboardLoading.value || isPostDashboardFetching.value)
provide('isLoading', isLoading)

const dashboardData = computed(() => postDashboard.value || {})
provide('dashboardData', dashboardData)

const dashboardError = computed(() => {
    const err = postDashboardError.value
    if (!err) {
        return null
    }

    return err?.message || t('text.post.dashboard_failed')
})
provide('dashboardError', dashboardError)

const dashboardLastFetchedAt = computed(() => {
    const updatedAt = postDashboardUpdatedAt.value
    if (!updatedAt) {
        return null
    }

    return dayjs(updatedAt).toISOString()
})
provide('dashboardLastFetchedAt', dashboardLastFetchedAt)

const refreshPostDashboard = (meta = {}) => {
    return refetchPostDashboard({
        meta: {
            reload: true,
            ...meta,
        },
    })
}
provide('refreshPostDashboard', refreshPostDashboard)
</script>
