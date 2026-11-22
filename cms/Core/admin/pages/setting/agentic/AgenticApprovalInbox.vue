<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <template v-if="canViewAgentic">
        <div class="space-y-4">
            <!-- Breadcrumbs -->
            <breadcrumbs
                :items="[
                    { name: 'setting-dashboard', title: $t('nav.a.settings'), icon: 'cog-sync' },
                    { name: 'setting-agentic', text: t('text.agentic.cms'), icon: 'robot' },
                    { text: t('text.agentic.inbox.title') },
                ]"
            />

            <!-- Secondary nav -->
            <AgenticSubNav />

            <!-- Two-column inbox layout -->
            <div class="flex flex-col gap-4 lg:flex-row">
                <!-- Sidebar: list -->
                <div class="w-full shrink-0 lg:w-80">
                    <!-- Status tabs -->
                    <a-segmented
                        v-model:value="statusFilter"
                        :options="statusTabs"
                        block
                        size="small"
                        class="mb-3"
                    />

                    <!-- Module filter -->
                    <a-select
                        v-model:value="approvalFilters.artifactType"
                        :options="moduleFilterOptions"
                        :placeholder="t('text.agentic.inbox.all_modules')"
                        allow-clear
                        class="mb-3 w-full"
                        size="small"
                    />

                    <!-- List -->
                    <div v-if="isLoading" class="py-8 text-center"><a-spin /></div>

                    <div v-else-if="approvals.length === 0" class="py-8 text-center text-sm text-slate-400">
                        {{ t('text.agentic.inbox.empty') }}
                    </div>

                    <div v-else class="space-y-1">
                        <div
                            v-for="approval in approvals"
                            :key="approval.id"
                            class="cursor-pointer rounded-lg border p-3 transition-colors"
                            :class="selectedId === approval.id ? 'border-blue-400 bg-blue-50' : 'border-blk-200/80 bg-white hover:border-blue-200'"
                            @click="selectApproval(approval)"
                        >
                            <div class="flex items-center gap-2">
                                <span class="text-sm" :class="statusIconClass(approval.status)">{{ statusIcon(approval.status) }}</span>
                                <router-link
                                    :to="{ name: 'setting-agentic-approval-detail', params: { id: approval.id } }"
                                    class="flex-1 truncate text-sm font-medium text-slate-800 hover:underline"
                                    @click.stop
                                >
                                    {{ artifactTypeLabel(approval.artifact?.artifact_type) }}
                                </router-link>
                            </div>
                            <div class="mt-1 truncate text-xs text-slate-500">
                                {{ approval.artifact?.subject_type?.split('\\').pop() }} #{{ approval.artifact?.subject_id }}
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                {{ timeAgo(approval.created_at) }}
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="meta.total > meta.per_page" class="mt-3 flex justify-center">
                        <a-pagination
                            v-model:current="approvalFilters.page"
                            :total="meta.total"
                            :page-size="meta.per_page"
                            size="small"
                            simple
                        />
                    </div>
                </div>

                <!-- Main panel: detail or empty -->
                <div class="min-h-[400px] flex-1">
                    <router-view v-if="$route.params.id" />

                    <div v-else-if="selectedId" class="rounded-xl border border-blk-200/80 bg-white p-6">
                        <!-- Inline detail for selected approval -->
                        <AgenticApprovalDetail :approval-id="selectedId" />
                    </div>

                    <div v-else class="flex h-full items-center justify-center rounded-xl border border-dashed border-blk-200/80 p-12">
                        <div class="text-center">
                            <div class="text-4xl">📋</div>
                            <div class="mt-3 text-sm text-slate-500">
                                {{ t('text.agentic.inbox.select_item') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
    <no-permission-error v-else />
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import { useAgenticMeta } from './composables/useAgenticMeta'
import { agenticModules } from './registry'
import { useAgenticFilters } from './composables/useAgenticFilters'
import { useAgenticApprovals } from '@/hooks'
import AgenticApprovalDetail from './AgenticApprovalDetail.vue'
import AgenticSubNav from './components/AgenticSubNav.vue'

defineOptions({ name: 'AgenticApprovalInbox' })

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const { canViewAgentic } = useAgenticPermissions()
const { artifactTypeLabel } = useAgenticMeta()
const { approvalFilters } = useAgenticFilters()

const statusFilter = ref('pending')

const statusTabs = computed(() => [
    { label: `${t('text.agentic.inbox.pending')} (${pendingCount.value})`, value: 'pending' },
    { label: t('text.agentic.inbox.approved'), value: 'approved' },
    { label: t('text.agentic.inbox.rejected'), value: 'rejected' },
    { label: t('text.agentic.inbox.all'), value: undefined },
])

/** A module that is not on this site carries no label, so its filter entry is left out (never a raw key). */
const moduleFilterOptions = computed(() =>
    [
        { key: 'text.agentic.module.content', value: 'content_post' },
        { key: 'text.agentic.module.product', value: 'product' },
        { key: 'text.agentic.module.education', value: 'education' },
        // Modules that registered an approval filter value.
        ...agenticModules().filter((module) => module.inboxFilter).map((module) => ({ key: module.labelKey, value: module.inboxFilter })),
    ]
        .filter((option) => te(option.key))
        .map((option) => ({ label: t(option.key), value: option.value })),
)

watch(statusFilter, (val) => {
    approvalFilters.status = val || undefined
    approvalFilters.page = 1
})

const { data: approvalQuery, isLoading } = useAgenticApprovals(approvalFilters, { refetchOnWindowFocus: true, refetchInterval: 30000 })
const approvals = computed(() => approvalQuery.value?.data || [])
const meta = computed(() => approvalQuery.value?.meta || {})

const pendingCount = ref(0)
watch(() => approvalQuery.value, (val) => {
    if (statusFilter.value === 'pending' && val?.meta?.total !== undefined) {
        pendingCount.value = val.meta.total
    }
}, { immediate: true })

const selectedId = ref(route.query.approval ? Number(route.query.approval) : (route.params.id ? Number(route.params.id) : null))

function selectApproval(approval) {
    selectedId.value = approval.id
    router.replace({ query: { ...route.query, approval: approval.id } })
}

function statusIcon(status) {
    const map = { pending: '●', approved: '✓', rejected: '✗', applied: '✓' }
    return map[status] || '○'
}

function statusIconClass(status) {
    const map = { pending: 'text-orange-500', approved: 'text-green-500', rejected: 'text-red-500', applied: 'text-green-600' }
    return map[status] || 'text-slate-400'
}

function timeAgo(dateStr) {
    if (!dateStr) return ''
    const diff = Date.now() - new Date(dateStr).getTime()
    const minutes = Math.floor(diff / 60000)
    if (minutes < 1) return t('text.agentic.time.just_now')
    if (minutes < 60) return `${minutes}m`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours}h`
    const days = Math.floor(hours / 24)
    return `${days}d`
}
</script>
