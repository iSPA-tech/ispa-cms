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
                text: $t('nav.a.users'),
                title: $t('nav.a.users'),
                icon: 'account-group',
            },
        ]"
    >
        <i-button as="router-link" :to="{ name: 'user-create' }" variant="primary">
            <template #icon>
                <i-icon class="size-3.5" icon="plus-thick" />
            </template>
            <span>
                {{ $t('nav.b.create') }}
            </span>
        </i-button>
    </breadcrumbs>
    <div class="h-full p-2 space-y-4">
        <div v-if="rule('user.viewAny')" class="px-2 md:px-1 py-3 mb-12 space-y-4">
            <i-filter
                ref="filterRef"
                :defaults="filterDefaults"
                :schema="filterSchema"
                :sync-route="true"
                @submit="handleFilterSubmit"
            >
                <template #roleControl>
                    <select-input-user-role
                        v-model="draftFilter.roles"
                        :multiple="true"
                        :placeholder="tt('text.user-tag.role_placeholder')"
                        class="w-full"
                        data-testid="filter-roles"
                        name="filter-roles"
                        size="small"
                    />
                </template>
                <template #tagControl>
                    <div class="flex flex-wrap items-center gap-2">
                        <select-input-user-tag
                            v-model="draftFilter.tags"
                            class="w-full sm:w-[170px]"
                            data-testid="filter-tags"
                            size="small"
                        />
                        <a-segmented
                            v-if="draftFilter.tags.length > 1"
                            v-model:value="draftFilter.match"
                            :options="matchOptions"
                            data-testid="filter-match"
                            size="small"
                        />
                    </div>
                </template>
            </i-filter>

            <i-bulk-action
                v-if="selectedRowKeys.length"
                v-model="selectedRowKeys"
                :actions="bulkActions"
                :base-actions="allByFilter ? [] : ['delete']"
                :requests="{
                    delete: deleteBulk,
                }"
                @success="handleBulkSuccess"
            >
                <template #summary>
                    <span v-if="allByFilter" class="text-xs text-gray-600" data-testid="all-selected">
                        {{ tt('text.user-tag.all_selected', { total }) }}
                        <button class="tag-link" data-testid="clear-all-filter" type="button" @click="allByFilter = false">
                            {{ tt('text.user-tag.clear_selection') }}
                        </button>
                    </span>
                    <button
                        v-else-if="canSelectAllByFilter"
                        class="tag-link text-xs"
                        data-testid="select-all-filter"
                        type="button"
                        @click="allByFilter = true"
                    >
                        {{ tt('text.user-tag.select_all_filter', { total }) }}
                    </button>
                </template>
                <template #extra-actions="{ disabled }">
                    <template v-if="rule('user.update')">
                        <i-button
                            :disabled="disabled"
                            data-testid="tag-attach"
                            size="sm"
                            variant="default"
                            @click="openTagModal('attach')"
                        >
                            <template #icon>
                                <i-icon class="size-3" icon="tag-multiple" />
                            </template>
                            {{ tt('text.user-tag.attach') }}
                        </i-button>
                        <a-tooltip :title="detachTagOptions.length ? '' : tt('text.user-tag.no_tags_on_selection')">
                            <span class="inline-flex">
                                <a-dropdown :disabled="disabled || !detachTagOptions.length || detaching" :trigger="['click']">
                                    <i-button
                                        :disabled="disabled || !detachTagOptions.length"
                                        :loading="detaching"
                                        data-testid="tag-detach"
                                        size="sm"
                                        variant="default"
                                    >
                                        <template #icon>
                                            <i-icon class="size-3" icon="tag-multiple" />
                                        </template>
                                        {{ tt('text.user-tag.detach') }}
                                    </i-button>
                                    <template #overlay>
                                        <a-menu data-testid="detach-menu">
                                            <a-menu-item
                                                v-for="tag in detachTagOptions"
                                                :key="tag.uuid"
                                                data-testid="detach-option"
                                                @click="detachOne(tag)"
                                            >
                                                {{ tag.name }}
                                            </a-menu-item>
                                        </a-menu>
                                    </template>
                                </a-dropdown>
                            </span>
                        </a-tooltip>
                    </template>
                </template>
            </i-bulk-action>

            <user-tag-apply-modal
                v-model:open="tagModalOpen"
                :count="tagModalCount"
                :filter="allByFilter ? bulkFilterPayload : null"
                :initial-tags="attachInitialTags"
                :user-uuids="selectedRowKeys"
                @done="onTagsChanged"
            />

            <a-table
                :columns="columns"
                :data-source="dataRef?.data?.data || []"
                :loading="isLoading || isFetching"
                :pagination="{
                    pageSize: dataRef?.data?.perPage || filters.pageSize || 10,
                    current: dataRef?.data?.currentPage || filters.page || 1,
                    total: dataRef?.data?.total || 0,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    showTotal: (total, range) => {
                        return `${$t('pagination.showing')} ${range[0]}-${range[1]} ${$t(
                            'pagination.of'
                        )} ${total} ${$t('pagination.results')}`
                    },
                }"
                :row-selection="rowSelection"
                row-key="uuid"
                size="small"
                @change="handleTableChange"
            >
                <template #headerCell="{ column }">
                    <template v-if="Array.isArray(column.title)">
                        {{ column.title.map((t) => $t(t)).join(' ') }}
                    </template>
                </template>

                <template #bodyCell="{ column: col, record: row, index }">
                    <template v-if="col.key === 'name'">
                        <div>
                            <p class="m-0 inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                                <router-link
                                    v-if="rule('user.update') && !row.isDeleted"
                                    :title="$t('nav.b.update')"
                                    :to="{
                                        name: 'user-update',
                                        params: { uuid: row.uuid },
                                    }"
                                    class="font-medium text-gray-700 hover:text-cyan-600"
                                >
                                    {{ row.name }}
                                </router-link>
                                <span
                                    v-else
                                    :class="{ 'line-through': row.isDeleted }"
                                    class="text-gray-700 font-medium"
                                >
                                    {{ row.name }}
                                </span>
                                <i-badge
                                    v-if="row.status"
                                    :type="resolveUserStatusBadge(row.status)"
                                    size="sm"
                                >
                                    {{ resolveUserStatusLabel(row.status, row.status_label) }}
                                </i-badge>
                                <span v-if="rowTags(row).length" class="inline-flex flex-wrap items-center gap-1" data-testid="row-tags">
                                    <span v-for="tag in rowTags(row).slice(0, 3)" :key="tag.uuid" class="tag-chip" data-testid="row-tag">
                                        <i-icon class="size-2.5" icon="tag-outline" />
                                        <span>{{ tag.name }}</span>
                                        <button
                                            v-if="rule('user.update')"
                                            :aria-label="tt('text.user-tag.remove_chip', { name: tag.name })"
                                            class="tag-chip-remove"
                                            data-testid="row-tag-remove"
                                            type="button"
                                            @click="removeRowTag(row, tag)"
                                        >
                                            <i-icon class="size-3" icon="close" />
                                        </button>
                                    </span>
                                    <a-tooltip v-if="rowTags(row).length > 3" :title="rowTags(row).slice(3).map((tag) => tag.name).join(', ')">
                                        <span class="text-xs text-gray-500" data-testid="row-tags-more" tabindex="0">+{{ rowTags(row).length - 3 }}</span>
                                    </a-tooltip>
                                </span>
                            </p>
                            <span
                                v-if="row.username"
                                :class="{ 'line-through': row.isDeleted }"
                                class="mt-0.5 flex items-center gap-1 text-gray-500 italic"
                            >
                                <span class="inline-flex items-center gap-1">
                                    <i-icon class="size-4" icon="fingerprint" />
                                    {{ row.username }}
                                </span>
                            </span>
                        </div>
                    </template>
                    <template v-else-if="col.key === 'email'">
                        <span
                            :class="{ 'line-through': row.isDeleted }"
                            class="text-gray-700 font-medium"
                        >
                            {{ row.email }}
                        </span>
                        <span v-if="row.mobile" class="block text-gray-400 italic">
                            {{ row.mobile }}
                        </span>
                    </template>
                    <template v-else-if="col.key === 'roles'">
                        <i-badge
                            v-for="role in row.roles"
                            :key="role.id"
                            :type="role.custom?.badge || 'danger'"
                            class="mr-1"
                            size="sm"
                        >
                            {{ role.name }}
                        </i-badge>
                    </template>
                    <template v-else-if="col.key === 'action'">
                        <div class="inline-flex items-center gap-2">
                            <template v-if="rule('user.view')">
                                <router-link
                                    :title="$t('nav.b.view-detail')"
                                    :to="{
                                        name: 'user-detail',
                                        params: { uuid: row.uuid },
                                    }"
                                    class="inline-flex items-center text-indigo-500"
                                >
                                    <i-icon class="size-4" icon="eye" />
                                </router-link>
                            </template>

                            <template
                                v-if="
                                    rule('user.password') &&
                                    accountData.id !== row.id &&
                                    accountData.level <= row.level &&
                                    !row.isDeleted
                                "
                            >
                                <span
                                    class="inline-flex items-center text-yellow-500 cursor-pointer"
                                    role="button"
                                    @click="resetPassword(row.uuid)"
                                >
                                    <i-icon class="size-4" icon="security" />
                                </span>
                            </template>
                            <template
                                v-if="
                                    rule('user.update') &&
                                    accountData.level <= row.level &&
                                    !row.isDeleted &&
                                    !row.emailVerifiedAt
                                "
                            >
                                <a-popconfirm
                                    :cancel-text="$t('nav.b.cancel')"
                                    :ok-text="$t('nav.b.send')"
                                    :title="
                                        $t('text.user.resend-verification-confirm')
                                    "
                                    ok-type="primary"
                                    @confirm="resendEmailVerification(row)"
                                >
                                    <button
                                        :aria-label="
                                            $t('text.user.resend-verification')
                                        "
                                        :disabled="resendingVerificationUuid === row.uuid"
                                        :title="
                                            $t('text.user.resend-verification')
                                        "
                                        class="inline-flex items-center border-0 bg-transparent p-0 text-cyan-600 disabled:cursor-wait disabled:opacity-50"
                                        data-testid="resend-email-verification"
                                        type="button"
                                    >
                                        <i-icon class="size-4" icon="email-outline" />
                                    </button>
                                </a-popconfirm>
                            </template>
                            <template
                                v-if="
                                    rule('user.signInAs') &&
                                    accountData.id !== row.id &&
                                    accountData.level <= row.level &&
                                    !row.isDeleted
                                "
                            >
                                <a-popconfirm
                                    :cancel-text="$t('nav.b.cancel')"
                                    :ok-text="$t('nav.b.sign-in')"
                                    :title="
                                        $t('nav.b.sign-in') +
                                        ' ' +
                                        (row.name || row.username || row.email || '')
                                    "
                                    ok-type="primary"
                                    @confirm="signInAs(row)"
                                >
                                    <span
                                        class="inline-flex items-center text-emerald-500 cursor-pointer"
                                    >
                                        <i-icon class="size-4" icon="login" />
                                    </span>
                                </a-popconfirm>
                            </template>

                            <template
                                v-if="
                                    rule('user.update') &&
                                    accountData.level <= row.level &&
                                    !row.isDeleted
                                "
                            >
                                <router-link
                                    :title="$t('nav.b.update')"
                                    :to="{
                                        name: 'user-update',
                                        params: { uuid: row.uuid },
                                    }"
                                    class="inline-flex items-center text-blue-500"
                                >
                                    <i-icon class="size-4" icon="edit" />
                                </router-link>
                            </template>
                            <template
                                v-if="
                                    rule('user.delete') &&
                                    accountData.id !== row.id &&
                                    accountData.level <= row.level &&
                                    !row.isDeleted
                                "
                            >
                                <a-popconfirm
                                    :cancel-text="$t('nav.b.cancel')"
                                    :ok-text="$t('nav.b.delete')"
                                    :title="$t('text.del-confirm')"
                                    ok-type="danger"
                                    @confirm="delUser(row.uuid)"
                                >
                                    <span
                                        class="inline-flex items-center cursor-pointer text-rose-500"
                                    >
                                        <i-icon class="size-4" icon="delete" />
                                    </span>
                                </a-popconfirm>
                            </template>
                        </div>
                    </template>
                </template>
            </a-table>
        </div>

        <a-modal
            v-model:open="modalState"
            :footer="false"
            :title="$t('nav.a.account-password')"
            @close="closeForm"
        >
            <a-form
                id="reset-user-password"
                ref="formRef"
                :model="form"
                :rules="formRule"
                layout="vertical"
                @finish="formSubmit"
            >
                <a-form-item :label="$t('text.auto-pws')" name="auto_password">
                    <div class="w-full">
                        <i-switch
                            v-model="form.auto_password"
                            :text-off="$t('text.turn-off')"
                            :text-on="$t('text.turn-on')"
                            :value-off="false"
                            :value-on="true"
                            size="small"
                        ></i-switch>
                    </div>
                    <p class="text-xs text-gray-500 whitespace-normal break-normal">
                        {{ $t('text.e.auto-pws') }}
                    </p>
                </a-form-item>

                <a-form-item
                    v-show="!form.auto_password"
                    :label="$t('validation.attributes.password')"
                    class="max-w-md"
                    name="password"
                >
                    <a-input-password
                        v-model:value="form.password"
                        :placeholder="$t('validation.attributes.password')"
                    />
                </a-form-item>
                <a-form-item
                    v-show="!form.auto_password"
                    :label="$t('validation.attributes.password_confirmation')"
                    class="max-w-md"
                    name="password_confirmation"
                >
                    <a-input-password
                        v-model:value="form.password_confirmation"
                        :placeholder="$t('validation.attributes.password_confirmation')"
                    />
                </a-form-item>

                <div class="space-x-2 text-right">
                    <a-button :disabled="btnState" @click="closeForm">
                        <span><i-icon icon="close" /></span>
                        <span>{{ $t('nav.b.cancel') }}</span>
                    </a-button>

                    <a-button :loading="btnState" danger html-type="submit" type="primary">
                        <span><i-icon icon="content-save" /></span>
                        <span>
                            {{ $t('nav.b.send') }}
                        </span>
                    </a-button>
                </div>
            </a-form>
        </a-modal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue'
import { iAlert, objectAssignOnlyByKeyHasValue, rule } from '@/mixins'
import { useI18n } from 'vue-i18n'
import {
    CACHE_KEY_ACCOUNT_DATA,
    CACHE_KEY_SIGN_IN_AS_META,
    COOKIE_KEY_LOCALE,
    COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE,
    COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN,
    COOKIE_KEY_TOKEN,
    defaultFilterParams,
} from '@/config'
import { makeTagText, userTagNames } from '@/components/user-tag-text'
import { useBulkSelection, useEnumerates, useUsersByFilter } from '@/hooks'
import UserTagApplyModal from '@/components/user-tag-apply-modal.vue'
import SelectInputUserRole from '@/components/select-input-user-role.vue'
import SelectInputUserTag from '@/components/select-input-user-tag.vue'
import { useRoute } from 'vue-router'
import { useStorage } from '@vueuse/core'
import { cookie } from '@/config/cookies'
import {
    deleteUser,
    attachUserTags,
    detachUserTags,
    resendUserEmailVerification,
    signInAsUser,
    updateUser,
    updateUserPassword,
} from '@/libs'
import { Form, message } from 'ant-design-vue'

const { useForm } = Form

defineComponent({
    name: 'UserManage',
})

// const dataRef = ref({})
const route = useRoute()
const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
const filterRef = ref(null)
const selectedRowKeys = ref([])
const { data: enumerates } = useEnumerates()

const filterDefaults = {
    keywords: '',
    status: 'all',
    page: defaultFilterParams.page || 1,
    pageSize: defaultFilterParams.pageSize || 20,
}

const filters = reactive({
    ...filterDefaults,
    keywords:
        typeof route.query.keywords === 'string' ? route.query.keywords : filterDefaults.keywords,
    status: typeof route.query.status === 'string' ? route.query.status : filterDefaults.status,
    page: Number(route.query.page) || filterDefaults.page,
    pageSize: Number(route.query.pageSize ?? route.query.page_size) || filterDefaults.pageSize,
})
// Role and tag are picked in the filter row (draft) and applied together with the other filters on "Lọc".
const draftFilter = reactive({ roles: [], tags: [], match: 'all' })
const tagFilter = ref({ roles: [], tags: [], match: 'all' })
const filterParams = computed(() => ({
    ...(filters.keywords && { q: filters.keywords }),
    status: filters.status || 'all',
    ...(tagFilter.value.roles.length && { roles: tagFilter.value.roles }),
    ...(tagFilter.value.tags.length && { tags: tagFilter.value.tags, match: tagFilter.value.match }),
    page: filters.page,
    per_page: filters.pageSize,
}))
// One data source: users/filter returns each user's roles and tags, with or without role/tag filters.
const listQuery = useUsersByFilter(filterParams)
const dataRef = computed(() => listQuery.data.value)
const isLoading = computed(() => listQuery.isLoading.value)
const isFetching = computed(() => listQuery.isFetching.value)
const refetch = () => listQuery.refetch()
const total = computed(() => dataRef.value?.data?.total || 0)

// "Chọn tất cả theo bộ lọc": tag actions then send the filter instead of ids.
const allByFilter = ref(false)
const bulkFilterPayload = computed(() => {
    const { q, status, roles, tags, match } = filterParams.value
    return { ...(q && { q }), status, ...(roles && { roles }), ...(tags && { tags, match }) }
})
const canSelectAllByFilter = computed(() => {
    const rows = dataRef.value?.data?.data?.length || 0
    return rows > 0 && total.value > rows && selectedRowKeys.value.length >= Math.min(rows, 20)
})
const tagModalOpen = ref(false)
const tagModalCount = computed(() => (allByFilter.value ? total.value : selectedRowKeys.value.length))
const openTagModal = () => {
    tagModalOpen.value = true
}
const detaching = ref(false)
const selectedRows = computed(() => {
    const keys = new Set(selectedRowKeys.value)
    return (dataRef.value?.data?.data || []).filter((row) => keys.has(row.uuid))
})
// Names of every tag the page has seen, so prefilled selects and menus show labels instead of uuids.
watch(() => dataRef.value, (value) => {
    (value?.data?.data || []).forEach((row) => (row.tags || []).forEach((tag) => userTagNames.set(tag.uuid, tag.name)))
}, { immediate: true })
// "Gắn tag" prefill: tags picked in the filter plus the tags every selected row already has (skipped for select-all-by-filter).
const attachInitialTags = computed(() => {
    const out = [...draftFilter.tags]
    if (!allByFilter.value && selectedRows.value.length) {
        const [first, ...rest] = selectedRows.value.map((row) => rowTags(row).map((tag) => tag.uuid))
        first.filter((uuid) => rest.every((list) => list.includes(uuid))).forEach((uuid) => { if (!out.includes(uuid)) out.push(uuid) })
    }
    return out
})
// "Bỏ tag" menu: tags present on the selected rows plus the filter tags (only the filter tags for select-all-by-filter).
const detachTagOptions = computed(() => {
    const seen = new Map()
    draftFilter.tags.forEach((uuid) => seen.set(uuid, userTagNames.get(uuid) || ''))
    if (!allByFilter.value) {
        selectedRows.value.forEach((row) => rowTags(row).forEach((tag) => { userTagNames.set(tag.uuid, tag.name); seen.set(tag.uuid, tag.name) }))
    }
    return [...seen].filter(([, name]) => name).map(([uuid, name]) => ({ uuid, name }))
})
const detachScope = () => (allByFilter.value ? { filter: bulkFilterPayload.value } : { user_uuids: [...selectedRowKeys.value] })
// "Bỏ tag" -> pick one tag from the menu: removed at once (no dialog), with an undo link in the toast.
const detachOne = async (tag) => {
    if (detaching.value) return
    detaching.value = true
    const scope = detachScope()
    const count = tagModalCount.value
    const rows = allByFilter.value ? users.value : selectedRows.value
    const before = rows.map((row) => [row.uuid, rowTags(row)])
    rows.forEach((row) => { tagOverride[row.uuid] = rowTags(row).filter((item) => item.uuid !== tag.uuid) })
    try {
        await detachUserTags({ tag_uuids: [tag.uuid], ...scope })
        const key = `detach-${tag.uuid}`
        const undo = async () => {
            message.destroy?.(key)
            before.forEach(([uuid, list]) => { tagOverride[uuid] = list })
            try {
                await attachUserTags({ tag_uuids: [tag.uuid], ...scope })
                await resyncTags()
            } catch (error) {
                rows.forEach((row) => { tagOverride[row.uuid] = rowTags(row).filter((item) => item.uuid !== tag.uuid) })
                message.error(error?.response?.data?.message || tt('text.user-tag.failed'))
            }
        }
        message.success({
            key,
            duration: 8,
            content: h('span', [
                tt('text.user-tag.detach_one_done', { name: tag.name, count }),
                h('button', { type: 'button', class: 'tag-link ml-2', 'data-testid': 'detach-undo', onClick: undo }, tt('text.user-tag.undo')),
            ]),
        })
        await resyncTags()
    } catch (error) {
        before.forEach(([uuid, list]) => { tagOverride[uuid] = list })
        message.error(error?.response?.data?.message || tt('text.user-tag.failed'))
    } finally {
        detaching.value = false
    }
}
// Optimistic per-user tag removal from a chip; `tagOverride` is dropped whenever fresh data arrives.
const tagOverride = reactive({})
const rowTags = (row) => tagOverride[row.uuid] ?? row.tags ?? []
const removeRowTag = async (row, tag) => {
    const before = rowTags(row)
    tagOverride[row.uuid] = before.filter((item) => item.uuid !== tag.uuid)
    try {
        await detachUserTags({ tag_uuids: [tag.uuid], user_uuids: [row.uuid] })
        message.success(tt('text.user-tag.removed', { name: tag.name }))
        await resyncTags()
    } catch (error) {
        tagOverride[row.uuid] = before
        message.error(error?.response?.data?.message || tt('text.user-tag.failed'))
    }
}
watch(() => dataRef.value, () => { Object.keys(tagOverride).forEach((key) => delete tagOverride[key]) })
// After any tag write: refetch, then drop the optimistic overrides. The watch above is not enough on its own: when the
// server state returns to what the cache already holds (remove a tag, then add it back), vue-query keeps the same data
// reference, the watch never fires and a stale override kept hiding the re-attached tag.
const resyncTags = async () => {
    try { await refetch() } finally { Object.keys(tagOverride).forEach((key) => delete tagOverride[key]) }
}
const onTagsChanged = () => {
    selectedRowKeys.value = []
    allByFilter.value = false
    resyncTags()
}
const baseColumns = [
    { key: 'name', title: ['validation.attributes.name', 'nav.a.users'] },
    { key: 'email', title: ['validation.attributes.email'] },
    { key: 'roles', title: ['nav.a.roles'] },
]
const columns = computed(() => [...baseColumns, { key: 'action', align: 'right' }])
const { t, te } = useI18n()
const tt = makeTagText({ t, te })
const matchOptions = computed(() => [
    { label: tt('text.user-tag.match_all'), value: 'all' },
    { label: tt('text.user-tag.match_any'), value: 'any' },
])

const statusOptions = computed(() => {
    const source = enumerates?.value?.data ?? enumerates?.value ?? []
    const list = Array.isArray(source) ? source : []
    const options = list
        .filter((item) => item?.type === 'user_status')
        .map((item) => ({
            label: `${item.name} (${item.enum_key})`,
            value: item.enum_key,
        }))

    return [{ label: t('text.filter.all'), value: 'all' }, ...options]
})

const statusLabelMap = computed(() => {
    const source = enumerates?.value?.data ?? enumerates?.value ?? []
    const list = Array.isArray(source) ? source : []
    const map = new Map()

    list.filter((item) => item?.type === 'user_status').forEach((item) => {
        if (item?.enum_key) {
            map.set(item.enum_key, item?.name || item?.enum_key)
        }
    })

    return map
})

const resolveUserStatusLabel = (status, fallbackLabel) => {
    if (typeof fallbackLabel === 'string' && fallbackLabel.trim().length) {
        return fallbackLabel
    }

    const key = String(status || '')
    const mapped = statusLabelMap.value.get(key)
    if (mapped) {
        return mapped
    }

    if (['user_banned', 'user_blocked', 'banned', 'blocked'].includes(key)) {
        return t('text.user.banned')
    }
    if (['user_inactive', 'inactive'].includes(key)) {
        return t('text.user.inactive')
    }
    if (['user_active', 'active'].includes(key)) {
        return t('text.user.active')
    }

    return key || t('text.unknown.title')
}

const resolveUserStatusBadge = (status) => {
    const key = String(status || '')
    if (['user_banned', 'user_blocked', 'banned', 'blocked'].includes(key)) {
        return 'danger'
    }
    if (['user_inactive', 'inactive'].includes(key)) {
        return 'warning'
    }
    if (['user_active', 'active'].includes(key)) {
        return 'success'
    }
    return 'default'
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
        key: 'status',
        type: 'select',
        placeholderKey: 'validation.attributes.status',
        options: statusOptions,
        allowClear: true,
        valueType: 'string',
        widthClass: 'w-32',
    },
    {
        key: 'filter_roles',
        type: 'slot',
        slotName: 'roleControl',
        widthClass: 'w-full sm:w-[150px]',
    },
    {
        key: 'filter_tags',
        type: 'slot',
        slotName: 'tagControl',
        widthClass: 'w-full sm:w-auto',
    },
])

const { rowSelection } = useBulkSelection(selectedRowKeys, {
    messageApi: message,
    maxMessage: t('text.bulk.max-items', { number: 20 }),
})

const users = computed(() => dataRef?.value?.data?.data || [])

const resolveUserIds = (keys = []) => {
    const list = Array.isArray(keys) ? keys : []
    const idMap = new Map()

    users.value.forEach((user) => {
        const key = user?.uuid ?? user?.id
        if (key) {
            idMap.set(String(key), String(key))
        }
    })

    const resolved = []
    const missing = []

    list.forEach((key) => {
        const mappedId = idMap.get(String(key))
        if (mappedId) {
            resolved.push(mappedId)
            return
        }

        missing.push(key)
    })

    return { uuids: resolved, missing }
}

const updateStatusBulk = async ({ ids = [], dry_run: dryRun } = {}, status) => {
    const resolvedKeys = Array.isArray(ids) ? ids : []
    const { uuids, missing } = resolveUserIds(resolvedKeys)

    if (dryRun) {
        return {
            status: 'success',
            data: {
                affected_count: uuids.length,
                eligible_uuids: uuids,
            },
            warnings: missing.length
                ? [t('text.user.bulk-missing')]
                : undefined,
        }
    }

    if (missing.length) {
        return {
            status: 'error',
            message: t('text.user.bulk-missing'),
        }
    }

    for (const uuid of uuids) {
        try {
            const response = await updateUser(uuid, { status })
            if (response?.status !== 'success') {
                return {
                    status: response?.status || 'error',
                    message:
                        response?.message ||
                        t('text.user.bulk-update-failed'),
                }
            }
        } catch (error) {
            return {
                status: 'error',
                message:
                    error?.response?.data?.message ||
                    error?.message ||
                    t('text.user.bulk-update-failed'),
            }
        }
    }

    return {
        status: 'success',
        data: {
            affected_count: uuids.length,
            eligible_uuids: uuids,
        },
    }
}

const banBulk = (payload) => updateStatusBulk(payload, 'user_banned')
const unbanBulk = (payload) => updateStatusBulk(payload, 'user_active')

const bulkActions = computed(() => allByFilter.value ? [] : [
    {
        key: 'ban',
        label: t('text.user.bulk-ban'),
        type: 'danger',
        icon: 'lock',
        confirm: {
            title: t('text.user.bulk-ban-confirm'),
            okText: t('text.user.bulk-ban'),
            cancelText: t('nav.b.cancel'),
            okType: 'danger',
        },
        request: banBulk,
    },
    {
        key: 'unban',
        label: t('text.user.bulk-unban'),
        type: 'success',
        icon: 'lock-open',
        confirm: {
            title: t('text.user.bulk-unban-confirm'),
            okText: t('text.user.bulk-unban'),
            cancelText: t('nav.b.cancel'),
            okType: 'success',
        },
        request: unbanBulk,
    },
])

//  This validation function must declare before formRule object
const validatePass = (rule, value) => {
    if (form.value.auto_password === false) {
        if (value === '') {
            return Promise.reject(
                t('validation.required', { a: t('validation.attributes.password') })
            )
        }
    }
    return Promise.resolve()
}
const validatePass2 = (rule, value) => {
    if (form.value.auto_password === false) {
        if (value === '') {
            return Promise.reject(
                t('validation.required', {
                    a: t('validation.attributes.password_confirmation'),
                })
            )
        } else if (value !== form.value.password) {
            return Promise.reject(
                t('validation.same', {
                    a: t('validation.attributes.password_confirmation'),
                    o: t('validation.attributes.password'),
                })
            )
        }
    }
    return Promise.resolve()
}
//  Reset password
const modalState = ref(false)
const btnState = ref(false)
const resendingVerificationUuid = ref('')
const form = ref({
    password: '',
    password_confirmation: '',
    auto_password: false,
})
const formRef = ref(null)
const formRule = reactive({
    password: [{ validator: validatePass, trigger: 'blur' }],
    password_confirmation: [{ validator: validatePass2, trigger: 'blur' }],
})
const { clearValidate, resetFields } = useForm(form.value, formRule)
const switchingUserState = ref(false)
const getCookieValue = (key) => {
    const allCookies = cookie.getAll()

    return typeof allCookies[key] === 'undefined' ? '' : allCookies[key]
}

/** Reset password */
const resetPassword = (uuid = '') => {
    form.value.uuid = uuid
    modalState.value = true
}

const resendEmailVerification = async (row = {}) => {
    const uuid = row?.uuid || ''

    if (!uuid || resendingVerificationUuid.value) return

    resendingVerificationUuid.value = uuid

    try {
        const res = await resendUserEmailVerification(uuid)
        if (res?.message) {
            iAlert(res, res?.status !== 'success')
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        resendingVerificationUuid.value = ''
    }
}

const closeForm = async () => {
    await resetFields()
    await clearValidate()

    modalState.value = false
}

const signInAs = async (row = {}) => {
    if (switchingUserState.value) return

    const uuid = row?.uuid || row?.id || ''
    if (!uuid) {
        iAlert(
            {
                message: t('text.user.sign-in-as-missing-account'),
            },
            true
        )
        return
    }

    const impersonateBackupToken = getCookieValue(COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN)
    if (impersonateBackupToken) {
        iAlert(
            {
                message: t('text.user.sign-in-as-existing-session'),
            },
            true
        )
        return
    }

    const backupToken = getCookieValue(COOKIE_KEY_TOKEN)
    if (!backupToken) {
        iAlert(
            {
                message: t('text.user.sign-in-as-missing-backup-token'),
            },
            true
        )
        return
    }

    switchingUserState.value = true
    try {
        const res = await signInAsUser(uuid)

        if (res?.status !== 'success') {
            if (res?.message) {
                iAlert(res, true)
            }
            return
        }

        const token = res?.data?.token
        if (!token) {
            iAlert(
                {
                    message: t('text.user.sign-in-as-token-missing'),
                },
                true
            )
            return
        }

        const backupLocale = getCookieValue(COOKIE_KEY_LOCALE)
        const fromUser = {
            id: accountData.value?.id || null,
            uuid: accountData.value?.uuid || null,
            name: accountData.value?.name || null,
            username: accountData.value?.username || null,
        }
        const targetUser = {
            id: row?.id || null,
            uuid: row?.uuid || uuid,
            name: row?.name || null,
            username: row?.username || null,
            email: row?.email || null,
        }

        cookie.set(COOKIE_KEY_IMPERSONATE_ORIGINAL_TOKEN, backupToken)
        if (backupLocale) {
            cookie.set(COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE, backupLocale)
        } else {
            cookie.remove(COOKIE_KEY_IMPERSONATE_ORIGINAL_LOCALE)
        }

        localStorage.setItem(
            CACHE_KEY_SIGN_IN_AS_META,
            JSON.stringify({
                fromUser,
                targetUser,
                startedAt: Date.now(),
            })
        )

        cookie.set(COOKIE_KEY_TOKEN, token)
        localStorage.removeItem(CACHE_KEY_ACCOUNT_DATA)

        if (res?.data?.locale) {
            cookie.set(COOKIE_KEY_LOCALE, res.data.locale)
        }

        if (res.message) {
            iAlert(res)
        }

        setTimeout(() => {
            window.location.reload()
        }, 500)
    } catch (error) {
        iAlert(error, true)
    } finally {
        switchingUserState.value = false
    }
}

/** Submit form to reset password */
const formSubmit = async (data = {}) => {
    if (btnState.value) return
    const value = objectAssignOnlyByKeyHasValue(form.value, data)

    if (btnState.value) {
        return
    }
    btnState.value = true
    try {
        const res = await updateUserPassword(value.uuid, value)
        if (res?.status === 'success') {
            await closeForm()
        }
        if (res?.message) {
            iAlert(res)
        }
    } catch (error) {
        iAlert(error, true)
    } finally {
        btnState.value = false
    }
}
//  Delete user by uuid
const delUser = async (uuid) => {
    await deleteUser(uuid)
        .then((res) => {
            if (res?.status === 'success') {
                refetch()
            }
            if (res?.message) {
                iAlert(res)
            }
        })
        .finally(() => {
            btnState.value = false
        })
}

const applyFilterValues = (payload = {}, { resetPage = true } = {}) => {
    Object.assign(filters, {
        ...filterDefaults,
        page: resetPage ? 1 : filters.page,
        pageSize: filters.pageSize,
        ...payload,
    })
    selectedRowKeys.value = []
    allByFilter.value = false
    refetch()
}

const handleFilterSubmit = (payload) => {
    tagFilter.value = { roles: [...draftFilter.roles], tags: [...draftFilter.tags], match: draftFilter.match }
    applyFilterValues(payload, { resetPage: true })
}

const handleTableChange = (pagination) => {
    filters.page = pagination.current || 1
    filters.pageSize = pagination.pageSize || filters.pageSize
    selectedRowKeys.value = []
    allByFilter.value = false
    filterRef.value?.applyRoute?.(filters, { replace: true })
    refetch()
}

const deleteBulk = async ({ ids = [], dry_run: dryRun } = {}) => {
    const resolvedIds = Array.isArray(ids) ? ids : []

    if (dryRun) {
        return {
            status: 'success',
            data: {
                affected_count: resolvedIds.length,
                eligible_ids: resolvedIds,
            },
        }
    }

    for (const uuid of resolvedIds) {
        try {
            const response = await deleteUser(uuid)
            if (response?.status !== 'success') {
                return {
                    status: response?.status || 'error',
                    message: response?.message || t('text.delete-failed'),
                }
            }
        } catch (error) {
            return {
                status: 'error',
                message: error?.response?.data?.message || error?.message || t('text.delete-failed'),
            }
        }
    }

    return {
        status: 'success',
        data: {
            affected_count: resolvedIds.length,
            ids: resolvedIds,
        },
    }
}

const handleBulkSuccess = ({ actionKey } = {}) => {
    if (actionKey === 'ban') {
        message.success(t('text.user.bulk-ban-success'))
    } else if (actionKey === 'unban') {
        message.success(t('text.user.bulk-unban-success'))
    } else if (actionKey === 'delete') {
        message.success(t('text.user.bulk-delete-success'))
    } else {
        message.success(t('text.user.bulk-success'))
    }
    refetch()
}

onMounted(() => {
    const values = filterRef.value?.getValues?.()
    if (values) {
        applyFilterValues(values, { resetPage: false })
    }
})
</script>

<style scoped>
.tag-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.25rem;
    background: #f9fafb;
    padding: 0 0.3rem;
    font-size: 0.6875rem;
    line-height: 1.125rem;
    color: #4b5563;
}
.tag-chip-remove {
    display: inline-flex;
    border: 0;
    background: transparent;
    padding: 0;
    cursor: pointer;
    color: #6b7280;
    opacity: 0;
}
.tag-chip:hover .tag-chip-remove,
.tag-chip-remove:focus-visible {
    opacity: 1;
}
.tag-chip-remove:hover { color: #b91c1c; }
.tag-chip-remove:focus-visible { outline: 2px solid #0e7490; outline-offset: 1px; }
@media (hover: none) {
    .tag-chip-remove { opacity: 1; }
}
.tag-link {
    border: 0;
    background: transparent;
    padding: 0;
    cursor: pointer;
    color: #0e7490;
    text-decoration: underline;
}
</style>
