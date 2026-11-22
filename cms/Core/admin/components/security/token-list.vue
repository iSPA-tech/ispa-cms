<template>
    <div class="space-y-3" :data-testid="`token-list-${mode}`">
        <!-- Filters (Webmaster listings only). -->
        <div v-if="mode !== 'mine' && !fixedUser" class="flex flex-wrap items-center gap-3" data-testid="token-filters">
            <select
                v-model="filters.audience"
                class="ant-input w-auto"
                :aria-label="t('text.security_tokens.filter.audience')"
                data-testid="filter-audience"
                @change="reloadFirstPage"
            >
                <option value="">{{ t('text.security_tokens.filter.all_apis') }}</option>
                <option v-for="a in AUDIENCES" :key="a" :value="a">{{ t(`text.security_tokens.audience.${a}`) }}</option>
            </select>
            <select
                v-model="filters.unused"
                class="ant-input w-auto"
                :aria-label="t('text.security_tokens.filter.unused')"
                data-testid="filter-unused"
                @change="reloadFirstPage"
            >
                <option value="">{{ t('text.security_tokens.filter.any_use') }}</option>
                <option value="30">{{ t('text.security_tokens.filter.unused_days', { days: 30 }) }}</option>
                <option value="90">{{ t('text.security_tokens.filter.unused_days', { days: 90 }) }}</option>
                <option value="180">{{ t('text.security_tokens.filter.unused_days', { days: 180 }) }}</option>
            </select>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="filters.never" type="checkbox" data-testid="filter-never" @change="reloadFirstPage" />
                {{ t('text.security_tokens.filter.never_expiring') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="filters.legacy" type="checkbox" data-testid="filter-legacy" @change="reloadFirstPage" />
                {{ t('text.security_tokens.filter.legacy') }}
            </label>
        </div>

        <a-alert
            v-if="notice"
            type="success"
            show-icon
            closable
            :message="notice"
            data-testid="token-notice"
            @close="notice = ''"
        />
        <a-alert
            v-if="actionError"
            type="error"
            show-icon
            closable
            :message="t(actionError)"
            data-testid="token-action-error"
            @close="actionError = ''"
        />

        <div v-if="loading && !items.length" role="status" data-testid="token-loading">
            <a-skeleton active :paragraph="{ rows: 4 }" />
        </div>
        <a-alert
            v-else-if="loadError"
            type="error"
            show-icon
            :message="t('text.security_tokens.list.load_error')"
            :description="t('text.security_tokens.list.load_error_hint')"
            data-testid="token-error"
        >
            <template #action>
                <i-button size="sm" data-testid="token-retry" @click="load">{{ t('text.security_tokens.list.retry') }}</i-button>
            </template>
        </a-alert>
        <template v-else>
            <!-- Narrow screens: one stacked card per token, so the expiry and the revoke button never sit off screen. -->
            <div v-if="compact" class="space-y-2" data-testid="token-cards">
                <p v-if="!items.length" class="py-6 text-center text-sm text-ink-muted" data-testid="token-cards-empty">
                    {{ t('text.security_tokens.list.empty') }}
                </p>
                <div
                    v-for="record in cardItems"
                    :key="record.uuid"
                    class="space-y-2 rounded-lg border border-border p-3"
                    :data-testid="`token-card-${record.uuid}`"
                >
                    <div class="font-medium break-words">{{ deviceOf(record) }}</div>
                    <div v-if="record.user" class="text-xs text-ink-muted break-words">{{ record.user.name }}</div>
                    <div v-if="record.network" class="text-xs text-ink-muted break-words" :data-testid="`token-network-${record.uuid}`">
                        {{ t('text.security_tokens.list.network') }}: {{ record.network }}
                    </div>
                    <a-tag v-if="record.current" color="blue" class="m-0" data-testid="token-current-badge">
                        {{ t('text.security_tokens.list.current') }}
                    </a-tag>
                    <dl class="m-0 grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-x-3 gap-y-1 text-sm">
                        <dt class="text-ink-muted">{{ t('text.security_tokens.list.api') }}</dt>
                        <dd class="m-0 break-words">
                            {{ record.audience ? t(`text.security_tokens.audience.${record.audience}`) : t('text.security_tokens.audience.legacy') }}
                        </dd>
                        <dt class="text-ink-muted">{{ t('text.security_tokens.list.signed_in') }}</dt>
                        <dd class="m-0 break-words">{{ formatTime(record.created_at) }}</dd>
                        <dt class="text-ink-muted">{{ t('text.security_tokens.list.last_used') }}</dt>
                        <dd class="m-0 break-words">
                            {{ record.last_used_at ? formatTime(record.last_used_at) : t('text.security_tokens.list.never_used') }}
                        </dd>
                        <dt class="text-ink-muted">{{ t('text.security_tokens.list.expires') }}</dt>
                        <dd class="m-0 break-words">
                            <a-tag v-if="record.never_expires" color="orange" class="m-0" data-testid="token-never-tag">
                                {{ t('text.security_tokens.list.never_expires') }}
                            </a-tag>
                            <span v-else>{{ formatTime(record.expires_at) }}</span>
                        </dd>
                    </dl>
                    <i-button
                        size="sm"
                        variant="danger"
                        :disabled="record.current || busy"
                        :data-testid="`token-revoke-${record.uuid}`"
                        @click="revokeOne(record)"
                    >
                        {{ t('text.security_tokens.list.revoke') }}
                    </i-button>
                    <div v-if="record.current" class="text-xs text-ink-muted" :data-testid="`token-current-hint-${record.uuid}`">
                        {{ t('text.security_tokens.list.current_hint') }}
                    </div>
                </div>
                <a-pagination
                    v-if="meta.total > cardPageSize"
                    size="small"
                    :current="cardPage"
                    :page-size="cardPageSize"
                    :total="meta.total"
                    :show-size-changer="false"
                    data-testid="token-cards-pagination"
                    @change="onCardPage"
                />
            </div>
            <a-table
                v-else
                size="small"
                row-key="uuid"
                :columns="columns"
                :data-source="items"
                :loading="loading"
                :pagination="paginationConfig"
                :scroll="{ x: 720 }"
                :locale="{ emptyText: t('text.security_tokens.list.empty') }"
                data-testid="token-table"
                @change="onTableChange"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'device'">
                        <div class="font-medium break-words">{{ deviceOf(record) }}</div>
                        <div v-if="record.user" class="text-xs text-ink-muted">{{ record.user.name }}</div>
                        <div v-if="record.network" class="text-xs text-ink-muted" :data-testid="`token-network-${record.uuid}`">
                            {{ t('text.security_tokens.list.network') }}: {{ record.network }}
                        </div>
                        <a-tag v-if="record.current" color="blue" class="m-0 mt-1" data-testid="token-current-badge">
                            {{ t('text.security_tokens.list.current') }}
                        </a-tag>
                    </template>
                    <template v-else-if="column.key === 'audience'">
                        {{ record.audience ? t(`text.security_tokens.audience.${record.audience}`) : t('text.security_tokens.audience.legacy') }}
                    </template>
                    <template v-else-if="column.key === 'created_at'">{{ formatTime(record.created_at) }}</template>
                    <template v-else-if="column.key === 'last_used_at'">
                        {{ record.last_used_at ? formatTime(record.last_used_at) : t('text.security_tokens.list.never_used') }}
                    </template>
                    <template v-else-if="column.key === 'expires_at'">
                        <a-tag v-if="record.never_expires" color="orange" class="m-0" data-testid="token-never-tag">
                            {{ t('text.security_tokens.list.never_expires') }}
                        </a-tag>
                        <span v-else>{{ formatTime(record.expires_at) }}</span>
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <i-button
                            size="sm"
                            variant="danger"
                            :disabled="record.current || busy"
                            :data-testid="`token-revoke-${record.uuid}`"
                            @click="revokeOne(record)"
                        >
                            {{ t('text.security_tokens.list.revoke') }}
                        </i-button>
                        <div v-if="record.current" class="mt-1 text-xs text-ink-muted" :data-testid="`token-current-hint-${record.uuid}`">
                            {{ t('text.security_tokens.list.current_hint') }}
                        </div>
                    </template>
                </template>
            </a-table>
        </template>

        <!-- Revoke all other sessions of this account (My devices). -->
        <div v-if="mode === 'mine'" class="flex flex-wrap items-center justify-between gap-2" data-testid="token-others-row">
            <span class="text-sm text-ink-muted">{{ t('text.security_tokens.list.others_hint') }}</span>
            <i-button
                variant="danger"
                size="md"
                :disabled="busy || !hasOthers"
                :loading="busy"
                data-testid="token-revoke-others"
                @click="revokeOthersNow"
            >
                {{ t('text.security_tokens.list.revoke_others') }}
            </i-button>
        </div>

        <step-up-dialog :open="stepUp.open.value" @confirmed="stepUp.confirmed" @cancel="stepUp.cancelled" />
    </div>
</template>

<script setup>
/**
 * API tokens of one account or of every account. `mode`: `mine` (the signed-in account, any operator; the list is short and
 * unpaged), `all` (Webmaster, every account, filtered and paged by the server) or `user` with `userUuid` (Webmaster, one
 * account; the same listing filtered by that user). A row shows the device label, the API, the network label, when it was
 * issued and last used, its expiry ("never" is flagged) and a revoke button; the row of the session in use carries a badge
 * and a disabled button with the reason. Every revoke goes through the step-up confirmation (single revokes reuse a held
 * grant, "revoke all others" uses it up). A token value never reaches this component: the API does not send it.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
    AUDIENCES,
    errorCode,
    fetchAllTokens,
    fetchMyTokens,
    revokeMyToken,
    revokeOthers,
    revokeToken,
    useStepUpRunner,
} from '@/libs/securityTokens'
import StepUpDialog from './step-up-dialog.vue'

const props = defineProps({
    /** `mine`, `all` or `user`. */
    mode: { type: String, default: 'mine', validator: (v) => ['mine', 'all', 'user'].includes(v) },
    /** Account uuid for `user` mode. */
    userUuid: { type: String, default: '' },
    /** Filters to start with (`never`, `legacy`), for example from a my-tasks link. */
    initialFilters: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['loaded', 'changed'])

const { t, locale } = useI18n()
const stepUp = useStepUpRunner()

const items = ref([])
const meta = reactive({ total: 0, page: 1, per_page: 25 })
const loading = ref(false)
const loadError = ref(false)
const busy = ref(false)
const notice = ref('')
const actionError = ref('')
const filters = reactive({
    audience: '',
    unused: '',
    never: Boolean(props.initialFilters?.never),
    legacy: Boolean(props.initialFilters?.legacy),
})
let disposed = false

/** Below this width (px) the list switches from a table to stacked cards. */
const COMPACT_WIDTH = 767
const compact = ref(false)
let compactQuery = null
const syncCompact = () => {
    compact.value = !!compactQuery?.matches
}
const cardPageSize = computed(() => (props.mode === 'mine' ? 10 : meta.per_page))
const cardPage = computed(() => (props.mode === 'mine' ? mineCardPage.value : meta.page))
const mineCardPage = ref(1)
/** Cards of the current page: "mine" pages the short list in the browser, the other modes are paged by the server. */
const cardItems = computed(() =>
    props.mode === 'mine'
        ? items.value.slice((mineCardPage.value - 1) * cardPageSize.value, mineCardPage.value * cardPageSize.value)
        : items.value
)
const onCardPage = (page) => {
    if (props.mode === 'mine') {
        mineCardPage.value = page
        return
    }
    meta.page = page
    load()
}

const fixedUser = computed(() => props.mode === 'user')
const hasOthers = computed(() => items.value.some((row) => !row.current))

const columns = computed(() => [
    { key: 'device', title: t('text.security_tokens.list.device'), dataIndex: 'label' },
    { key: 'audience', title: t('text.security_tokens.list.api'), dataIndex: 'audience' },
    { key: 'created_at', title: t('text.security_tokens.list.signed_in'), dataIndex: 'created_at' },
    { key: 'last_used_at', title: t('text.security_tokens.list.last_used'), dataIndex: 'last_used_at' },
    { key: 'expires_at', title: t('text.security_tokens.list.expires'), dataIndex: 'expires_at' },
    { key: 'actions', title: '', dataIndex: 'uuid' },
])

const paginationConfig = computed(() =>
    props.mode === 'mine'
        ? { pageSize: 10, hideOnSinglePage: true, showSizeChanger: false, size: 'small' }
        : { current: meta.page, pageSize: meta.per_page, total: meta.total, showSizeChanger: false, size: 'small' }
)

/** Token names the sign-in flows give every token; they say nothing about the device. */
const GENERIC_NAMES = ['authtoken', 'auth_token', 'token']

/** Device text: the short device label of the sign-in, else a token name an operator gave, else "unknown device". */
const deviceOf = (row) => {
    const label = String(row.label || '').trim()
    const named = label && !GENERIC_NAMES.includes(label.toLowerCase()) ? label : ''
    return row.device || named || t('text.security_tokens.list.unknown_device')
}

/** Local time of an ISO string; Vietnamese uses the 24-hour clock ("15:05 01/11/2026"). */
const formatTime = (iso) => {
    if (!iso) return '-'
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return '-'
    const code = String(locale?.value || 'vi')
    if (code.startsWith('vi')) {
        const pad = (n) => String(n).padStart(2, '0')
        return `${pad(date.getHours())}:${pad(date.getMinutes())} ${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`
    }
    return date.toLocaleString(code, { dateStyle: 'medium', timeStyle: 'short' })
}

/** Query of the Webmaster listing from the filters. */
const query = () => {
    const params = { page: meta.page, per_page: meta.per_page }
    if (props.mode === 'user') params.user = props.userUuid
    if (filters.audience) params.audience = filters.audience
    if (filters.never) params.never_expiring = 1
    if (filters.legacy) params.legacy = 1
    if (filters.unused) params.last_used_before = new Date(Date.now() - Number(filters.unused) * 86400000).toISOString()
    return params
}

/** Load the list. */
const load = async () => {
    if (loading.value) return
    loading.value = true
    loadError.value = false
    try {
        if (props.mode === 'mine') {
            const body = await fetchMyTokens()
            if (disposed) return
            items.value = Array.isArray(body.data) ? body.data : []
            meta.total = items.value.length
        } else {
            const body = (await fetchAllTokens(query()))?.data || {}
            if (disposed) return
            items.value = Array.isArray(body.items) ? body.items : []
            Object.assign(meta, body.meta || {})
        }
        emit('loaded', { total: meta.total })
    } catch {
        if (!disposed) loadError.value = true
    } finally {
        if (!disposed) loading.value = false
    }
}

const reloadFirstPage = () => {
    meta.page = 1
    load()
}

const onTableChange = (pagination) => {
    // "mine" holds the whole short list; the table pages it in the browser.
    if (props.mode === 'mine') return
    meta.page = pagination?.current || 1
    load()
}

/** Map a failed revoke to a translated message key. */
const failureKey = (err) => {
    const code = errorCode(err)
    if (code === 'step_up_locked' || code === 'step_up_email_limit') return 'text.security_tokens.list.action_locked'
    if (code === 'CURRENT_TOKEN_NOT_REVOCABLE') return 'text.security_tokens.list.current_hint'
    return 'text.security_tokens.list.action_error'
}

/**
 * Run a revoke under the busy guard. The action runs now when a grant is held, or after the confirmation dialog; in both
 * cases it refreshes the list after a success. A `step_up_required` answer goes back to the runner, which asks again.
 */
const perform = (call, consume, message) => {
    if (busy.value || stepUp.open.value) return undefined
    const action = async (grant) => {
        busy.value = true
        actionError.value = ''
        try {
            const body = await call(grant)
            if (disposed) return
            notice.value = message(body?.data || {})
            emit('changed')
            busy.value = false
            await load()
        } catch (err) {
            if (errorCode(err) === 'step_up_required') throw err
            if (!disposed) actionError.value = failureKey(err)
        } finally {
            if (!disposed) busy.value = false
        }
    }
    return stepUp.run(action, { consume })
}

const revokeOne = (row) => {
    if (row.current) return
    const call = props.mode === 'mine' ? revokeMyToken : revokeToken
    return perform((grant) => call(row.uuid, grant), false, () => t('text.security_tokens.list.revoked_one'))
}

const revokeOthersNow = () =>
    perform((grant) => revokeOthers(grant), true, (data) => t('text.security_tokens.list.revoked_others', { count: data.revoked ?? 0 }))

onMounted(() => {
    if (typeof window !== 'undefined' && window.matchMedia) {
        compactQuery = window.matchMedia(`(max-width: ${COMPACT_WIDTH}px)`)
        syncCompact()
        compactQuery.addEventListener?.('change', syncCompact)
    }
    load()
})
onBeforeUnmount(() => {
    compactQuery?.removeEventListener?.('change', syncCompact)
    disposed = true
    stepUp.cancelled()
})

defineExpose({ load })
</script>
