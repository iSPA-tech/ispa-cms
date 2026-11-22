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
                text: $t('nav.a.comments'),
                title: $t('nav.a.comments'),
                icon: 'comment',
            },
        ]"
    >
        <i-button
            v-if="rule('comment.viewAny')"
            data-testid="comment-settings-open"
            @click="openSettings"
        >
            <template #icon>
                <i-icon class="size-3.5" icon="cog" />
            </template>
            <span>{{ $t('nav.a.settings') }}</span>
        </i-button>
    </breadcrumbs>

    <div class="i-box space-y-3">
        <template v-if="rule('comment.viewAny')">
            <!-- Quick status views: the moderation queue is one click away. -->
            <a-tabs
                :active-key="activeStatus"
                class="[&_.ant-tabs-nav]:mb-0!"
                data-testid="comment-status-tabs"
                size="small"
                @change="changeStatus"
            >
                <a-tab-pane v-for="tab in statusTabs" :key="tab.key" :tab="$t(tab.label)" />
            </a-tabs>

            <!-- `status` stays an allowed query key (so a search keeps the open tab) but its select is hidden. -->
            <i-filter
                :key="`${route.name}-${activeStatus}`"
                :allowedFields="filterFields"
                :disable-fields="['status']"
                :module="getModuleCommentName(route.name)"
                status-group="status"
            />

            <i-bulk-action
                v-if="selectedRowKeys.length"
                v-model="selectedRowKeys"
                :actions="bulkActions"
                :base-actions="[]"
                :texts="bulkActionTexts"
                @success="handleBulkSuccess"
            />

            <a-table
                :columns="columns"
                :data-source="dataRef?.data?.data || []"
                :loading="isFetching"
                :locale="{ emptyText: $t('text.no-comments') }"
                :pagination="{
                    pageSize: dataRef?.data?.perPage || 10,
                    current: dataRef?.data?.currentPage || 1,
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
                row-key="id"
                size="small"
                @change="tableChange"
            >
                <template #bodyCell="{ column: col, record: row }">
                    <template v-if="col.key === 'comment'">
                        <div class="min-w-0 space-y-1.5">
                            <!-- Below `lg` the other columns are hidden, so the row stacks its own meta. -->
                            <div
                                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-faint lg:hidden"
                            >
                                <span class="font-semibold text-ink break-all">
                                    {{ row.name || '—' }}
                                </span>
                                <span>{{ formatDate(row.created_at) }}</span>
                                <a-tag :color="statusMeta(row).color" class="m-0!">
                                    {{ statusMeta(row).label }}
                                </a-tag>
                                <a-tag
                                    v-if="bannedWordsOf(row).length"
                                    class="m-0!"
                                    color="volcano"
                                    data-testid="comment-banned-flag"
                                >
                                    {{ $t('text.comment.banned_flag') }}
                                </a-tag>
                            </div>
                            <button
                                class="block w-full cursor-pointer text-left"
                                data-testid="comment-text"
                                type="button"
                                @click="showComment(row)"
                            >
                                <span
                                    :class="row.isDeleted ? 'text-ink-faint line-through' : 'text-ink'"
                                    class="line-clamp-2 text-sm break-words whitespace-pre-line"
                                >
                                    {{ row.content || row.subject || '—' }}
                                </span>
                            </button>
                            <p
                                v-if="row.target?.name"
                                class="m-0 line-clamp-1 text-xs text-ink-faint lg:hidden"
                            >
                                {{ row.target.name }}
                            </p>
                            <comment-row-actions
                                :busy="busy"
                                :can-update="canUpdate"
                                :comment="row"
                                can-delete
                                class="pt-0.5 lg:hidden"
                                @approve="approveComment"
                                @delete="delComment"
                                @reject="rejectComment"
                                @restore="resComment"
                                @view="showComment"
                            />
                        </div>
                    </template>
                    <template v-else-if="col.key === 'author'">
                        <p class="m-0 font-medium text-ink break-words">{{ row.name || '—' }}</p>
                        <p v-if="row.contact?.email" class="m-0 text-xs text-ink-faint break-all">
                            {{ row.contact.email }}
                        </p>
                        <p class="m-0 text-xs text-ink-faint whitespace-nowrap">
                            {{ formatDate(row.created_at) }}
                        </p>
                    </template>
                    <template v-else-if="col.key === 'target'">
                        <router-link
                            v-if="targetRoute(row)"
                            :to="targetRoute(row)"
                            class="line-clamp-2 text-sm text-primary-ink hover:underline"
                        >
                            {{ row.target.name }}
                        </router-link>
                        <span v-else class="line-clamp-2 text-sm text-ink-muted">
                            {{ row.target?.name || '—' }}
                        </span>
                    </template>
                    <template v-else-if="col.key === 'status'">
                        <div class="flex flex-wrap items-center gap-1">
                            <a-tag :color="statusMeta(row).color" class="m-0!">
                                {{ statusMeta(row).label }}
                            </a-tag>
                            <a-tag
                                v-if="bannedWordsOf(row).length"
                                class="m-0!"
                                color="volcano"
                                data-testid="comment-banned-flag"
                            >
                                {{ $t('text.comment.banned_flag') }}
                            </a-tag>
                        </div>
                    </template>
                    <template v-else-if="col.key === 'action'">
                        <comment-row-actions
                            :busy="busy"
                            :can-update="canUpdate"
                            :comment="row"
                            can-delete
                            class="justify-end"
                            @approve="approveComment"
                            @delete="delComment"
                            @reject="rejectComment"
                            @restore="resComment"
                            @view="showComment"
                        />
                    </template>
                </template>
            </a-table>

            <a-drawer
                v-model:open="currentCommentState"
                :title="currentComment.name || $t('nav.a.comments')"
                :width="$isMobile() ? '100%' : '50%'"
            >
                <div class="space-y-4" data-testid="comment-detail">
                    <div class="flex flex-wrap items-center gap-2">
                        <a-tag :color="statusMeta(currentComment).color" class="m-0!">
                            {{ statusMeta(currentComment).label }}
                        </a-tag>
                        <span class="text-xs text-ink-faint">
                            {{ formatDate(currentComment.created_at) }}
                        </span>
                        <i-button
                            v-if="canUpdate && !currentComment.isDeleted && !editing"
                            class="ml-auto"
                            data-testid="comment-edit"
                            size="xs"
                            @click="startEdit"
                        >
                            <template #icon>
                                <i-icon class="size-3.5" icon="edit" />
                            </template>
                            <span>{{ $t('nav.b.edit') }}</span>
                        </i-button>
                    </div>

                    <!-- The server list is the truth; the marks in the text below are a reading aid. -->
                    <div
                        v-if="currentBannedWords.length"
                        class="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm break-words text-warning-ink"
                        data-testid="comment-banned-found"
                        role="status"
                    >
                        <i-icon class="mt-0.5 size-4 shrink-0" icon="alert-outline" />
                        <span class="min-w-0">
                            {{ $t('text.comment.banned_found', { words: currentBannedWords.join(', ') }) }}
                        </span>
                    </div>

                    <div v-if="editing" class="space-y-2">
                        <a-textarea
                            v-model:value="editContent"
                            :auto-size="{ minRows: 4, maxRows: 14 }"
                            :disabled="savingEdit"
                            data-testid="comment-edit-input"
                        />
                        <div class="flex flex-wrap justify-end gap-2">
                            <i-button
                                :disabled="savingEdit"
                                data-testid="comment-edit-cancel"
                                variant="ghost"
                                @click="editing = false"
                            >
                                {{ $t('nav.b.cancel') }}
                            </i-button>
                            <i-button
                                :disabled="savingEdit || !String(editContent || '').trim()"
                                :loading="savingEdit"
                                data-testid="comment-edit-save"
                                variant="primary"
                                @click="saveEdit"
                            >
                                {{ $t('nav.b.save') }}
                            </i-button>
                        </div>
                    </div>
                    <div
                        v-else
                        class="max-h-80 overflow-auto rounded-md border border-border bg-surface-sunken p-3 text-sm break-words whitespace-pre-line text-ink"
                        data-testid="comment-detail-text"
                    >
                        <template v-for="(segment, index) in contentSegments" :key="index"
                            ><mark
                                v-if="segment.hit"
                                class="rounded-sm bg-warning-soft px-0.5 font-semibold text-warning-ink"
                                data-testid="comment-banned-hit"
                                >{{ segment.text }}</mark
                            ><template v-else>{{ segment.text }}</template></template
                        >
                    </div>

                    <dl class="m-0 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_1fr] sm:gap-y-3">
                        <dt class="text-ink-faint">{{ $t('text.comment.sender') }}</dt>
                        <dd class="m-0 mb-2 break-words text-ink sm:mb-0">
                            {{ currentComment.name || '—' }}
                        </dd>
                        <template v-if="currentComment.contact?.email">
                            <dt class="text-ink-faint">{{ $t('validation.attributes.email') }}</dt>
                            <dd class="m-0 mb-2 break-all text-ink sm:mb-0">
                                {{ currentComment.contact.email }}
                            </dd>
                        </template>
                        <dt class="text-ink-faint">{{ targetLabel }}</dt>
                        <dd class="m-0 mb-2 break-words text-ink sm:mb-0">
                            <router-link
                                v-if="targetRoute(currentComment)"
                                :to="targetRoute(currentComment)"
                                class="text-primary-ink hover:underline"
                                data-testid="comment-target-link"
                            >
                                {{ currentComment.target.name }}
                            </router-link>
                            <template v-else>{{ currentComment.target?.name || '—' }}</template>
                        </dd>
                        <dt class="text-ink-faint">{{ $t('text.comment.sent_at') }}</dt>
                        <dd class="m-0 mb-2 text-ink sm:mb-0">
                            {{ formatDate(currentComment.created_at) }}
                        </dd>
                        <template v-if="currentComment.last_view">
                            <dt class="text-ink-faint">{{ $t('text.last_view') }}</dt>
                            <dd class="m-0 text-ink-muted">
                                {{ formatDate(currentComment.last_view) }}
                            </dd>
                        </template>
                    </dl>
                </div>
                <template #footer>
                    <comment-row-actions
                        :busy="busy"
                        :can-update="canUpdate"
                        :comment="currentComment"
                        :show-view="false"
                        can-delete
                        class="justify-end"
                        @approve="approveComment"
                        @delete="delComment"
                        @reject="rejectComment"
                        @restore="resComment"
                    />
                </template>
            </a-drawer>

            <a-drawer
                v-model:open="settingsOpen"
                :title="$t('text.comment.settings_title')"
                :width="$isMobile() ? '100%' : 480"
            >
                <section class="space-y-2" data-testid="comment-settings">
                    <h3 class="m-0 text-sm font-semibold text-ink">
                        {{ $t('text.comment.banned_words') }}
                    </h3>
                    <p class="m-0 text-xs text-ink-muted">
                        {{ $t('text.comment.banned_words_help') }}
                    </p>
                    <a-skeleton v-if="settingsLoading" :paragraph="{ rows: 4 }" :title="false" active />
                    <div
                        v-else-if="settingsError"
                        class="flex flex-wrap items-center gap-2 rounded-md bg-danger-soft px-3 py-2 text-sm text-danger-ink"
                        data-testid="comment-settings-error"
                        role="alert"
                    >
                        <span class="min-w-0 flex-1">{{ $t('text.comment.settings_load_failed') }}</span>
                        <i-button size="xs" @click="loadSettings">{{ $t('nav.b.retry') }}</i-button>
                    </div>
                    <a-textarea
                        v-else
                        v-model:value="bannedText"
                        :aria-label="$t('text.comment.banned_words')"
                        :auto-size="{ minRows: 8, maxRows: 20 }"
                        :disabled="!canUpdate || settingsSaving"
                        data-testid="comment-settings-input"
                    />
                </section>
                <template #footer>
                    <div class="flex flex-wrap justify-end gap-2">
                        <i-button variant="ghost" @click="settingsOpen = false">
                            {{ $t('nav.b.close') }}
                        </i-button>
                        <i-button
                            v-if="canUpdate"
                            :disabled="settingsLoading || settingsError || settingsSaving"
                            :loading="settingsSaving"
                            data-testid="comment-settings-save"
                            variant="primary"
                            @click="saveSettings"
                        >
                            {{ $t('nav.b.save') }}
                        </i-button>
                    </div>
                </template>
            </a-drawer>
        </template>
        <no-permission-error v-else></no-permission-error>
    </div>
</template>

<script setup>
import { computed, defineComponent, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import dayjs from 'dayjs'
import { getRouteType, iAlert, initFilter, rule } from '@/mixins'
import { commentItemFilter, commentPlaceFilter } from '@/config'
import { useBulkSelection, useComments } from '@/hooks'
import { deleteComment, getComment, restoreComment, updateComment } from '@/libs'
import { useAppRuntime } from '@/runtime'
import IBulkAction from '@/components/common/i-bulk-action.vue'
import CommentRowActions from './comment-row-actions.vue'
import {
    bannedWordsOf,
    getCommentSettings,
    highlightSegments,
    linesToWords,
    saveCommentSettings,
} from '../comment-banned-words'

defineComponent({
    name: 'Comment',
})

const { t } = useI18n()
const { locale } = useAppRuntime()
const route = useRoute()
const router = useRouter()

const getModuleCommentName = (routeName = '') => {
    switch (routeName) {
        case 'place-comment':
            return 'place_comment'
        case 'item-comment':
            return 'item_comment'
        case 'post-comment':
            return 'post_comment'
        default:
            return 'item_comment'
    }
}

/** List params for the open route: its default filter, the URL query, and the target type of the route. */
const buildQuery = () =>
    initFilter(route.name === 'place-comment' ? commentPlaceFilter : commentItemFilter, {
        ...route.query,
        type: getRouteType(route.name),
    })
const queryData = reactive(buildQuery())
const { data: dataRef, refetch, isFetching } = useComments(queryData)

// Search always; the target picker only on routes that have one (`placeId`, ...).
const filterFields = computed(() =>
    ['keywords', 'status', getRouteType(route.name, 'ids')].filter(Boolean)
)

/**
 * Status tabs map 1:1 to the list `status` param: `all` includes trashed rows, `deleted` lists only trashed rows,
 * anything else is matched against the stored status.
 */
const statusTabs = [
    { key: 'all', label: 'text.filter.all' },
    { key: 'status_pending', label: 'text.comment.status_pending' },
    { key: 'status_published', label: 'text.comment.status_published' },
    { key: 'rejected', label: 'text.comment.status_rejected' },
    { key: 'deleted', label: 'text.product.filter_deleted' },
]
const activeStatus = computed(() => String(route.query.status || 'all'))
const changeStatus = (key) => {
    const query = { ...route.query, status: key, page: 1 }
    if (key === 'all') {
        delete query.status
    }
    router.push({ name: route.name, query })
}

const canUpdate = computed(() => rule('comment.update'))
const targetLabel = computed(() =>
    t(getRouteType(route.name, 'columnLabels')?.[0] || 'text.comment.target')
)
const columns = computed(() => [
    { key: 'comment', title: t('nav.a.comments') },
    { key: 'author', title: t('text.comment.sender'), width: 200, responsive: ['lg'] },
    { key: 'target', title: targetLabel.value, width: 220, responsive: ['lg'] },
    { key: 'status', title: t('validation.attributes.status'), width: 130, responsive: ['lg'] },
    { key: 'action', align: 'right', width: 260, responsive: ['lg'] },
])

const formatDate = (value) =>
    value && dayjs(value).isValid() ? dayjs(value).format('DD/MM/YYYY HH:mm') : '—'

/**
 * One tag per comment. The public form saves `status_pending` (`pending` is the older value); approval writes
 * `status_published` (`confirmed` is the older value); `rejected` is a literal with no enum row, so it is labelled here.
 */
const statusMeta = (comment = {}) => {
    if (comment.isDeleted) {
        return { label: t('text.product.filter_deleted'), color: 'default' }
    }
    switch (comment.status) {
        case 'status_pending':
        case 'pending':
            return { label: t('text.comment.status_pending'), color: 'warning' }
        case 'status_published':
        case 'confirmed':
            return { label: t('text.comment.status_published'), color: 'success' }
        case 'rejected':
            return { label: t('text.comment.status_rejected'), color: 'error' }
    }
    const enumRow = comment.comment_status
    const enumLabel = locale.value === 'en' ? enumRow?.name_alias || enumRow?.name : enumRow?.name

    return { label: enumLabel || comment.status || '—', color: 'default' }
}

/** Admin screen of the commented post. Needs the post `uuid`, which the detail response carries. */
const targetRoute = (comment = {}) => {
    const uuid = comment.target?.uuid
    if (!uuid || !String(comment.target_type || '').endsWith('\\Post') || !router.hasRoute('post-view')) {
        return null
    }

    return { name: 'post-view', params: { uuid } }
}

const currentComment = ref({})
const currentCommentState = ref(false)
const busy = ref(false)

/** Run one moderation call, report the backend message, then refresh the list and close the drawer. */
const runAction = async (request) => {
    if (busy.value) return
    busy.value = true
    try {
        const res = await request()
        const ok = res?.status === 'success'
        if (res?.message) {
            iAlert(res.message, !ok)
        }
        if (ok) {
            currentCommentState.value = false
            refetch()
        }
    } catch (err) {
        iAlert(err, true)
    } finally {
        busy.value = false
    }
}

const approveRequest = (id) => updateComment(`${id}/confirm`)
const rejectRequest = (id) => updateComment(`${id}/status`, { type: 'rejected' })

const approveComment = (comment) => runAction(() => approveRequest(comment.id))
const rejectComment = (comment) => runAction(() => rejectRequest(comment.id))
const delComment = (comment) => runAction(() => deleteComment(comment.id))
const resComment = (comment) => runAction(() => restoreComment(comment.id))

/** Load the full record (post link, contact, banned words matched on the saved text). */
const loadDetail = async (id) => {
    try {
        const { data } = await getComment(id)
        if (data && currentComment.value.id === id) {
            currentComment.value = data
        }
    } catch (err) {
        iAlert(err, true)
    }
}

/** Open the drawer at once from the row, then load the full record. Trashed rows have no detail. */
const showComment = async (comment) => {
    editing.value = false
    currentComment.value = { ...comment }
    currentCommentState.value = true
    if (comment.isDeleted) return
    await loadDetail(comment.id)
}

//  Banned words: the server says which list entries a comment matches; the page warns, it never blocks.
const currentBannedWords = computed(() => bannedWordsOf(currentComment.value))
const contentSegments = computed(() => {
    const text = currentComment.value.content || currentComment.value.subject || '—'

    return highlightSegments(text, currentBannedWords.value)
})

//  Edit the comment text in the drawer. The sender name is sent back unchanged.
const editing = ref(false)
const editContent = ref('')
const savingEdit = ref(false)
const startEdit = () => {
    editContent.value = currentComment.value.content || ''
    editing.value = true
}
const saveEdit = async () => {
    if (savingEdit.value) return
    const { id, name } = currentComment.value
    savingEdit.value = true
    try {
        const res = await updateComment(id, { name, content: editContent.value })
        const ok = res?.status === 'success'
        if (res?.message) {
            iAlert(res.message, !ok)
        }
        if (ok) {
            editing.value = false
            // The matched words are recomputed by the server on the saved text.
            await loadDetail(id)
            refetch()
        }
    } catch (err) {
        iAlert(err, true)
    } finally {
        savingEdit.value = false
    }
}

//  Comment settings drawer: the banned word list, one word or phrase per line.
const settingsOpen = ref(false)
const settingsLoading = ref(false)
const settingsError = ref(false)
const settingsSaving = ref(false)
const bannedText = ref('')
const loadSettings = async () => {
    settingsLoading.value = true
    settingsError.value = false
    try {
        const res = await getCommentSettings()
        if (res?.status && res.status !== 'success') {
            throw new Error(res?.message || 'comment-settings')
        }
        bannedText.value = bannedWordsOf(res?.data).join('\n')
    } catch {
        settingsError.value = true
    } finally {
        settingsLoading.value = false
    }
}
const openSettings = () => {
    settingsOpen.value = true
    loadSettings()
}
const saveSettings = async () => {
    if (settingsSaving.value) return
    settingsSaving.value = true
    try {
        const res = await saveCommentSettings({ banned_words: linesToWords(bannedText.value) })
        const ok = res?.status === 'success'
        if (res?.message) {
            iAlert(res.message, !ok)
        }
        if (ok) {
            bannedText.value = bannedWordsOf(res?.data).join('\n')
            settingsOpen.value = false
            // Flags are computed on read, so the list (and an open comment) must be loaded again.
            refetch()
            if (currentCommentState.value && currentComment.value.id && !currentComment.value.isDeleted) {
                loadDetail(currentComment.value.id)
            }
        }
    } catch (err) {
        iAlert(err, true)
    } finally {
        settingsSaving.value = false
    }
}

//  Bulk moderation. There is no bulk endpoint, so each selected comment is sent on its own (20 at most).
const selectedRowKeys = ref([])
const { rowSelection } = useBulkSelection(selectedRowKeys, {
    messageApi: message,
    maxMessage: t('text.bulk.max-items', { number: 20 }),
})
const bulkActionTexts = computed(() => ({
    selectedLabel: t('text.selected'),
    maxMessage: t('text.bulk.max-items', { number: 20 }),
    rateLimitMessage: t('text.rate_limit'),
}))
const bulkRequest =
    (request) =>
    async ({ ids, dry_run } = {}) => {
        const targetIds = Array.isArray(ids) ? ids : []
        if (!targetIds.length) {
            return { status: 'error', message: t('text.bulk.no-items-selected') }
        }
        if (dry_run) {
            return {
                status: 'success',
                data: { affected_count: targetIds.length, eligible_ids: targetIds, preview: true },
            }
        }
        for (const id of targetIds) {
            const res = await request(id)
            if (res?.status && res.status !== 'success') {
                return { status: 'error', message: res?.message || t('text.comment.bulk_failed') }
            }
        }

        return { status: 'success', data: { affected_count: targetIds.length, ids: targetIds } }
    }
const selectedBannedCount = computed(() => {
    const rows = dataRef.value?.data?.data || []

    return rows.filter((row) => selectedRowKeys.value.includes(row.id) && bannedWordsOf(row).length)
        .length
})
const bulkActions = computed(() => {
    if (activeStatus.value === 'deleted') {
        return [
            {
                key: 'restore',
                label: t('nav.b.restore'),
                icon: 'restore',
                request: bulkRequest(restoreComment),
            },
        ]
    }

    const actions = []
    if (canUpdate.value) {
        if (activeStatus.value !== 'status_published') {
            actions.push({
                key: 'approve',
                label: t('nav.b.confirm'),
                icon: 'check-circle-outline',
                type: 'primary',
                // Selected comments that still hold banned words are named in the confirm before anything is sent.
                confirm: selectedBannedCount.value
                    ? { content: t('text.comment.banned_bulk_confirm', { count: selectedBannedCount.value }) }
                    : undefined,
                request: bulkRequest(approveRequest),
            })
        }
        if (activeStatus.value !== 'rejected') {
            actions.push({
                key: 'reject',
                label: t('nav.b.reject'),
                icon: 'close',
                request: bulkRequest(rejectRequest),
            })
        }
    }
    actions.push({
        key: 'delete',
        label: t('nav.b.delete'),
        icon: 'delete',
        danger: true,
        confirm: {
            title: t('text.bulk.delete-selected'),
            okText: t('nav.b.delete'),
            cancelText: t('nav.b.cancel'),
            okType: 'danger',
        },
        request: bulkRequest(deleteComment),
    })

    return actions
})
const handleBulkSuccess = () => {
    message.success(t('text.comment.bulk_done'))
    selectedRowKeys.value = []
    refetch()
}

const tableChange = (pagination) => {
    router.push({
        name: route.name,
        query: {
            ...route.query,
            page: pagination.current,
            pageSize: pagination.pageSize,
        },
    })
}

// The URL is the source of truth: a tab, search, page change or a move between comment routes reloads the list.
watch(
    () => [route.name, route.query],
    () => {
        Object.keys(queryData).forEach((key) => delete queryData[key])
        Object.assign(queryData, buildQuery())
        selectedRowKeys.value = []
    }
)
</script>
