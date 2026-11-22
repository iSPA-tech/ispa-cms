<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <breadcrumbs
        :items="[
            {
                name: 'contact-dashboard',
                title: $t('nav.a.contacts'),
                icon: 'email-outline',
            },
            {
                text: $t('nav.a.contact-manage'),
                title: $t('nav.a.contact-manage'),
                icon: 'card-account-mail',
            },
        ]"
    >
        <div class="ml-auto flex items-center space-x-2">
            <a-button
                size="small"
                :type="isShowroomInquiryScope ? 'default' : 'primary'"
                @click="applyScope(false)"
            >
                <span>{{ $t('text.contact.scope_all') }}</span>
            </a-button>
            <a-button
                v-if="canViewShowroomInquiryScope"
                size="small"
                :type="isShowroomInquiryScope ? 'primary' : 'default'"
                @click="applyScope(true)"
            >
                <span>{{ $t('text.contact.scope_showroom_inquiries') }}</span>
            </a-button>
            <a-button size="small" @click="handleExport" :loading="exportLoading">
                <i-icon icon="download" class="size-4" />
                <span class="ml-1">{{ $t('nav.a.export') }}</span>
            </a-button>
            <a-button size="small" @click="importModalVisible = true">
                <i-icon icon="upload" class="size-4" />
                <span class="ml-1">{{ $t('nav.a.import') }}</span>
            </a-button>
        </div>
    </breadcrumbs>

    <div class="i-box" v-if="rule('contact.viewAny')">
        <i-filter module="contact" status-group="user_status"></i-filter>

        <a-table
            :columns="columns"
            :data-source="dataRef?.data || []"
            :pagination="{
                pageSize: dataRef?.perPage || 10,
                current: dataRef?.currentPage || 1,
                total: dataRef?.total || 0,
                showSizeChanger: true,
                pageSizeOptions: ['10', '20', '50', '100'],
                showTotal: (total, range) => {
                    return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t('pagination.of')} ${total} ${$t(
                        'pagination.results'
                    )}`
                },
            }"
            row-key="id"
            size="small"
            @change="tableChange"
        >
            <template #headerCell="{ column }">
                <template v-if="Array.isArray(column.title)">
                    {{ column.title.map((t) => $t(t)).join(' ') }}
                </template>
            </template>

            <template #bodyCell="{ column: col, record: row }">
                <template v-if="col.key === 'name'">
                    <span class="inline-flex flex-wrap items-center gap-x-2">
                        <router-link
                            :to="{ name: 'contact-view', params: { id: row.id } }"
                            class="text-gray-800 hover:text-indigo-600 transition-all font-medium"
                        >
                            {{ row.name }}
                        </router-link>
                        <span
                            :class="[row.is_subscribe ? 'text-green-500' : 'text-gray-500']"
                            class="inline-flex items-center"
                        >
                            <i-icon class="size-3" icon="check-circle" />
                        </span>
                    </span>
                </template>
                <template v-else-if="col.key === 'information'">
                    <span>{{ row.email || '' }}</span>
                    <p class="m-0 text-gray-500">{{ row.mobile || '' }}</p>
                    <p v-if="row.subject" class="m-0 text-xs text-gray-400 truncate">
                        {{ row.subject }}
                    </p>
                </template>
                <template v-else-if="col.key === 'type'">
                    <a-tag v-if="row.type" color="green">{{ row.type }}</a-tag>
                    <span v-else class="text-gray-400">—</span>
                </template>
                <template v-else-if="col.key === 'source'">
                    <div v-if="row.showroom_context" class="space-y-1">
                        <a-tag color="cyan">{{ $t('text.contact.showroom_inquiry') }}</a-tag>
                        <p class="m-0 text-sm font-medium text-gray-700">
                            {{ row.showroom_context.name }}
                        </p>
                        <p class="m-0 text-xs text-gray-500">
                            {{ row.showroom_context.address || row.showroom_context.uid || row.source }}
                        </p>
                    </div>
                    <a-tag v-else-if="row.source_label" color="blue">{{ row.source_label }}</a-tag>
                    <span v-else class="text-gray-400">—</span>
                </template>
                <template v-else-if="col.key === 'status'">
                    <a-tag :color="statusMeta(row.status).color">
                        {{ statusMeta(row.status).label }}
                    </a-tag>
                </template>
                <template v-else-if="col.key === 'action'">
                    <div class="flex items-center justify-end space-x-1">
                        <router-link
                            v-if="rule('contact.viewAny')"
                            :to="{ name: 'contact-view', params: { id: row.id } }"
                            class="cursor-pointer text-gray-500 hover:text-indigo-500 transition-all"
                        >
                            <i-icon icon="eye" class="size-4" />
                        </router-link>
                        <router-link
                            v-if="rule('contact.update')"
                            :to="{ name: 'contact-update', params: { id: row.id } }"
                            class="cursor-pointer text-blue-500 hover:text-blue-600 transition-all"
                        >
                            <i-icon icon="pencil" class="size-4" />
                        </router-link>
                        <a-popconfirm
                            v-if="rule('contact.delete')"
                            :cancel-text="$t('nav.b.cancel')"
                            :ok-text="$t('nav.b.delete')"
                            :title="$t('text.del-confirm')"
                            ok-type="danger"
                            @confirm="delContact(row.id)"
                        >
                            <span class="cursor-pointer text-rose-500 hover:text-rose-600 transition-all">
                                <i-icon class="size-4" icon="delete" />
                            </span>
                        </a-popconfirm>
                    </div>
                </template>
            </template>
        </a-table>
    </div>
    <no-permission-error v-else></no-permission-error>

    <import-modal
        v-model:open="importModalVisible"
        :import-function="importContacts"
        entity="contacts"
        :entity-label="$t('text.contact.entity_label')"
        module="App"
        @success="handleImportSuccess"
    />
</template>

<script setup>
import { computed, defineComponent, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { iAlert, initFilter, rule } from '@/mixins'
import { contactFilter } from '@/config'
import { useRoute, useRouter } from 'vue-router'
import { getUiExtension } from '@/runtime/uiExtensions'
import { useActiveModules, useContacts } from '@/hooks'
import { deleteContact, importContacts, exportContacts } from '@/libs'
import ImportModal from '@/components/common/ImportModal.vue'

defineComponent({
    name: 'ContactManage',
})
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const queryData = reactive(initFilter(contactFilter, route.query))
const { isModuleActive } = useActiveModules()
const { data: dataRef, refetch } = useContacts(queryData, {
    keepPreviousData: true,
})
// The inquiry scope (a source prefix and the module that owns it) is registered by a module as the `contact.inquiry-scope`
// UI extension; without one the contact screens have no inquiry scope.
const inquiryScope = computed(() => getUiExtension('contact.inquiry-scope'))
const showroomInquirySourcePrefix = computed(() => inquiryScope.value?.sourcePrefix || '')
const isInquiryQuery = (query) =>
    query.type === 'showroom_inquiry' || (showroomInquirySourcePrefix.value !== '' && query.sourcePrefix === showroomInquirySourcePrefix.value)
const canViewShowroomInquiryScope = computed(() => Boolean(inquiryScope.value) && isModuleActive(inquiryScope.value.moduleName))
const isShowroomInquiryScope = computed(() => {
    return canViewShowroomInquiryScope.value && isInquiryQuery(queryData)
})

const delContact = async (id) => {
    try {
        const res = await deleteContact(id)
        if (res.data.message) {
            iAlert(res.data)
        }
        if (res.data.status === 'success') {
            await refetch()
        }
    } catch (err) {
        iAlert(err, true)
    }
}

const columns = ref([
    {
        key: 'name',
        title: ['validation.attributes.name', 'nav.a.contacts'],
    },
    {
        key: 'information',
        title: ['text.s.contact'],
    },
    {
        key: 'type',
        title: ['validation.attributes.type'],
    },
    {
        key: 'source',
        title: ['nav.a.source'],
    },
    {
        key: 'status',
        title: ['validation.attributes.status'],
    },
    { key: 'action', align: 'right' },
])

const statusMeta = (status = 'new') => {
    const map = {
        new: { label: t('text.contact.status_new'), color: 'default' },
        assigned: { label: t('text.contact_dashboard.status_assigned'), color: 'gold' },
        replied: { label: t('text.contact_dashboard.status_replied'), color: 'green' },
        pending: { label: t('text.contact.status_pending'), color: 'orange' },
    }

    return map[status] || { label: status, color: 'default' }
}

const applyScope = async (showroomOnly = false) => {
    const nextQuery = {
        ...queryData,
        page: 1,
    }

    if (showroomOnly && canViewShowroomInquiryScope.value) {
        nextQuery.type = 'showroom_inquiry'
        nextQuery.sourcePrefix = showroomInquirySourcePrefix.value
    } else {
        nextQuery.type = ''
        nextQuery.sourcePrefix = ''
    }

    await router.push({
        name: 'contact-manage',
        query: nextQuery,
    })
}

const tableChange = (pagination) => {
    const newFilterParams = {
        ...queryData,
        page: pagination.current,
        pageSize: pagination.pageSize,
    }

    router.push({
        name: 'contact-manage',
        query: newFilterParams,
    })
}

watch(
    () => route.query,
    (val) => {
        if (Object.keys(val).length < 1) {
            Object.assign(queryData, contactFilter)
        } else {
            Object.assign(queryData, initFilter(contactFilter, val))
        }
    }
)

watch(canViewShowroomInquiryScope, async (canView) => {
    const hasShowroomQuery = isInquiryQuery(queryData)

    if (!canView && hasShowroomQuery) {
        await applyScope(false)
    }
}, { immediate: true })

/** Import */
const importModalVisible = ref(false)

const handleImportSuccess = async () => {
    await refetch()
    importModalVisible.value = false
}

/** Export */
const exportLoading = ref(false)

const handleExport = async () => {
    exportLoading.value = true
    try {
        const res = await exportContacts(queryData)
        const blob = new Blob([res.data], {
            type: res.headers['content-type'] || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        })
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `contacts-${new Date().toISOString().slice(0, 10)}.xlsx`
        link.click()
        window.URL.revokeObjectURL(url)
    } catch (err) {
        iAlert(err, true)
    } finally {
        exportLoading.value = false
    }
}
</script>
