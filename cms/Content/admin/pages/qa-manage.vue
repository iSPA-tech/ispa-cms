<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4">
        <breadcrumbs
            :items="[
                {
                    icon: 'text-box-edit-outline',
                    title: $t('nav.a.posts'),
                },
                {
                    text: $t('text.qa.title'),
                    title: $t('text.qa.title'),
                },
            ]"
        >
            <i-button v-if="can('post', 'create')" @click="goToCreate">
                <template #icon>
                    <i-icon class="size-3.5" icon="plus-thick" />
                </template>
                <span>{{ $t('nav.b.create') }}</span>
            </i-button>
        </breadcrumbs>

        <div class="i-box space-y-3">
            <template v-if="can(moduleName, 'viewAny')">
                <i-filter
                    :key="route.name"
                    ref="filterRef"
                    :defaults="filterDefaults"
                    :schema="filterSchema"
                    :sync-route="true"
                    @submit="handleFilterSubmit"
                />

                <a-skeleton v-if="isLoading || isFetching" :rows="5" active />
                <a-table
                    v-else
                    :columns="columns"
                    :data-source="dataRef?.data || []"
                    :pagination="{
                        pageSize: dataRef?.perPage || 10,
                        current: dataRef?.currentPage || 1,
                        total: dataRef?.total || 0,
                        showSizeChanger: true,
                        pageSizeOptions: ['10', '20', '50', '100'],
                        showTotal: (total, range) => {
                            return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t(
                                'pagination.of'
                            )} ${total} ${$t('pagination.results')}`
                        },
                    }"
                    row-key="uuid"
                    size="small"
                    @change="tableChange"
                >
                    <template #headerCell="{ column }">
                        <template v-if="column.key === 'name'">
                            <span>
                                {{ $t('validation.attributes.name') }}
                                ({{ $t('text.qa.title') }})
                            </span>
                        </template>
                        <template v-else-if="column.key === 'questioner'">
                            <span>{{ $t('text.qa.asker') }}</span>
                        </template>
                        <template v-else-if="column.key === 'email'">
                            <span>{{ $t('text.qa.asker_email') }}</span>
                        </template>
                        <template v-else-if="column.key === 'status'">
                            <span class="whitespace-nowrap">
                                {{ $t('validation.attributes.status') }}
                            </span>
                        </template>
                        <template v-else-if="column.key === 'category'">
                            <span>
                                {{ $t('nav.a.categories') }}
                            </span>
                        </template>
                    </template>

                    <template #bodyCell="{ column: col, record: row }">
                        <div v-if="col.key === 'name'" class="flex items-center">
                            <span
                                class="text-cyan-500 hover:text-cyan-700 cursor-pointer inline-flex items-center gap-2"
                                role="button"
                                @click="goToQuestionUpdate(row)"
                            >
                                <i-icon class="size-4" icon="comment-question-outline" />
                                {{ row.name }}
                            </span>
                            <span
                                class="text-green-500 cursor-pointer inline-flex items-center gap-1 ml-2"
                                role="button"
                                :title="$t('text.qa.answer')"
                                @click="goToAnswer(row)"
                            >
                                →
                                <span>{{ $t('nav.b.reply') }}</span>
                                <i-icon
                                    v-if="hasAnswer(row)"
                                    class="size-4 text-green-600"
                                    icon="check-circle"
                                    :title="$t('text.qa.answered')"
                                />
                            </span>
                        </div>
                        <template v-else-if="col.key === 'questioner'">
                            {{ getQuestionBlockValue(row, 'question_name') || $t('text.not_applicable') }}
                        </template>
                        <template v-else-if="col.key === 'email'">
                            {{ getQuestionBlockValue(row, 'question_email') || $t('text.not_applicable') }}
                        </template>
                        <template v-else-if="col.key === 'category'">
                            {{ row.category?.name || $t('text.not_applicable') }}
                        </template>
                        <template v-else-if="col.key === 'status'">
                            <a-switch
                                v-model:checked="row.status"
                                checkedValue="status_published"
                                unCheckedValue="status_draft"
                                @change="
                                    (checked) => {
                                        onChangeStatus(checked, row.uuid)
                                    }
                                "
                            />
                        </template>
                        <template v-else>
                            <div
                                v-if="row.id > 0"
                                class="flex items-center justify-end gap-2 whitespace-nowrap"
                            >
                                <span
                                    class="text-blue-500 cursor-pointer"
                                    role="button"
                                    :title="$t('text.qa.question')"
                                    @click="goToQuestionUpdate(row)"
                                >
                                    <i-icon class="size-4" icon="pencil" />
                                </span>
                                <a-popconfirm
                                    :cancel-text="$t('nav.b.cancel')"
                                    :ok-text="$t('nav.b.delete')"
                                    :title="$t('text.del-confirm')"
                                    ok-type="danger"
                                    @confirm="() => delPost(row.uuid)"
                                >
                                    <span class="text-rose-500 cursor-pointer" role="button">
                                        <i-icon class="size-4" icon="delete" />
                                    </span>
                                </a-popconfirm>
                            </div>
                        </template>
                    </template>
                </a-table>
            </template>
            <no-permission-error v-else />
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, nextTick, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { can } from '@/mixins'
import { CATEGORY_TYPE, CATEGORY_TYPE_STR_POST } from '@/config'
import { deletePost } from '@/libs'
import { useCategories, usePosts, useQuickPost } from '@/hooks'

defineComponent({
    name: 'PostQaManage',
})

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { mutate: updatePostStatus } = useQuickPost()

const moduleName = 'post'
const filterDefaults = {
    keywords: '',
    categoryId: [],
    status: 'all',
    page: 1,
    pageSize: 20,
    type: 'qa',
}

const filters = reactive({
    ...filterDefaults,
    page: Number(route.query.page) || filterDefaults.page,
    pageSize: Number(route.query.pageSize ?? route.query.page_size) || filterDefaults.pageSize,
})

const {
    data: dataRef,
    refetch,
    isLoading,
    isFetching,
} = usePosts(filters, {
    staleTime: 1000 * 60 * 5,
    enabled: route.name === 'post-qa',
})

const { data: categories } = useCategories({
    type: CATEGORY_TYPE[CATEGORY_TYPE_STR_POST],
})

const findBlockByType = (content, blockType) => {
    if (Array.isArray(content)) {
        return (
            content.find((item = {}) => item.block_type === blockType || item.type === blockType) ||
            {}
        )
    }

    if (content && typeof content === 'object') {
        if (content?.[blockType] && typeof content[blockType] === 'object') {
            return content[blockType]
        }

        if (Array.isArray(content.blocks)) {
            return (
                content.blocks.find(
                    (item = {}) => item.block_type === blockType || item.type === blockType
                ) || {}
            )
        }

        for (const item of Object.values(content)) {
            if (
                item &&
                typeof item === 'object' &&
                (item.block_type === blockType || item.type === blockType)
            ) {
                return item
            }
        }
    }

    return {}
}

const getQuestionBlock = (row = {}) => {
    const questionBlock = row?.content?.question
    if (questionBlock && typeof questionBlock === 'object') {
        return questionBlock
    }

    if (typeof row.content === 'string') {
        try {
            const parsed = JSON.parse(row.content)
            return findBlockByType(parsed, 'question')
        } catch {
            return {}
        }
    }

    return findBlockByType(row.content, 'question')
}

const getAnswerBlock = (row = {}) => {
    if (typeof row.content === 'string') {
        try {
            const parsed = JSON.parse(row.content)
            return findBlockByType(parsed, 'answer')
        } catch {
            return {}
        }
    }

    return findBlockByType(row.content, 'answer')
}

const getQuestionBlockValue = (row = {}, key = '') => {
    if (key === 'question_name' && row?.question_name && String(row.question_name).trim()) {
        return row.question_name
    }

    if (key === 'question_email' && row?.question_email && String(row.question_email).trim()) {
        return row.question_email
    }

    const block = getQuestionBlock(row)
    const blockValue = String(block?.question?.[key] || '')

    if (blockValue.trim()) {
        return blockValue
    }

    if (key === 'question_name') {
        return (
            block?.question_name ||
            block?.name ||
            block?.asker_name ||
            block?.question?.name ||
            block?.question?.asker_name ||
            block?.value ||
            ''
        )
    }

    if (key === 'question_email') {
        return (
            block?.question_email ||
            block?.email ||
            block?.asker_email ||
            block?.question?.email ||
            block?.question?.asker_email ||
            ''
        )
    }

    return block?.[key]
}

const hasAnswer = (row = {}) => {
    if (String(row?.has_answer || '').trim()) {
        return String(row?.has_answer) === '1' || row?.has_answer === true
    }

    const answerBlock = getAnswerBlock(row)
    return Boolean(
        String(answerBlock?.answer_content || answerBlock?.content || '').trim() ||
        String(answerBlock?.responder_name || '').trim() ||
        answerBlock?.answered_at
    )
}

const onChangeStatus = (checked, uuid) => {
    updatePostStatus(
        { id: uuid, status: checked },
        {
            onSuccess: () => {
                refetch()
            },
        }
    )
}

const tableChange = (pagination) => {
    filters.page = pagination.current
    filters.pageSize = pagination.pageSize
    refetch()
}

const filterSchema = computed(() => [
    {
        key: 'keywords',
        type: 'search',
        placeholderKey: 'nav.b.search',
        debounce: 400,
        valueType: 'string',
        widthClass: 'w-56',
    },
    {
        key: 'categoryId',
        type: 'cascader',
        placeholderKey: 'text.select',
        options: categoryOptions,
        allowClear: true,
        changeOnSelect: true,
        valueType: 'array',
        arrayItemType: 'number',
        widthClass: 'w-48',
    },
    {
        key: 'status',
        type: 'status',
        placeholderKey: 'validation.attributes.status',
        allowClear: true,
        emitChange: true,
        valueType: 'string',
        widthClass: 'w-32',
    },
])

const mapCategoryOptions = (items = []) => {
    return items.map((item) => ({
        label: item.id > 0 ? item.name : t('text.filter.all'),
        value: item.id > 0 ? parseInt(item.id, 10) : 'all',
        children: mapCategoryOptions(item.children || []),
    }))
}

const categoryOptions = computed(() => {
    const payload = categories?.value?.data ?? categories?.value ?? {}
    const list = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : []

    return [
        { label: t('text.filter.no_category'), value: 0 },
        ...mapCategoryOptions(list),
    ]
})

const filterRef = ref(null)
const applyFilterValues = (payload = {}, { resetPage = true } = {}) => {
    Object.assign(filters, {
        ...filterDefaults,
        page: resetPage ? 1 : filters.page,
        pageSize: filters.pageSize,
        type: 'qa',
        ...payload,
    })
    refetch()
}

const handleFilterSubmit = (payload) => {
    applyFilterValues(payload, { resetPage: true })
}

const syncFiltersFromRoute = async () => {
    if (route.name !== 'post-qa') {
        return
    }

    await nextTick()

    const values = filterRef.value?.getValues?.() || {}
    filters.page = Number(route.query.page) || filterDefaults.page
    filters.pageSize =
        Number(route.query.pageSize ?? route.query.page_size) || filterDefaults.pageSize

    applyFilterValues(values, { resetPage: false })
}

watch(
    () => route.name,
    (nextName, prevName) => {
        if (nextName === prevName) {
            return
        }
        syncFiltersFromRoute()
    }
)

const goToCreate = () => {
    router.push({ name: 'post-qa-question-create' })
}

const goToQuestionUpdate = (record = {}) => {
    router.push({
        name: 'post-qa-question-update',
        params: { uuid: record.uuid || record.id },
    })
}

const goToAnswer = (record = {}) => {
    router.push({
        name: 'post-qa-answer',
        params: { uuid: record.uuid || record.id },
    })
}

const columns = [
    {
        key: 'name',
        width: 280,
    },
    {
        key: 'questioner',
        width: 220,
    },
    {
        key: 'email',
        width: 220,
    },
    {
        key: 'category',
        width: 180,
    },
    {
        key: 'status',
        width: 80,
    },
    {
        fixed: 'right',
        width: 145,
        align: 'right',
    },
]

const delPost = (id = 0) => {
    deletePost(id).then(() => {
        refetch()
    })
}
</script>
