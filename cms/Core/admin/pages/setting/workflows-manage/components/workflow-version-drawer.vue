<!--
  Version history of one workflow: every published version (who, when, note, which one runs), what changed between a
  version and the draft or another version in plain words, and "return to this version". Returning never re-points to an
  old row: it publishes a new version with the same content, through the same checks as any publish.
-->
<template>
    <a-drawer
        :open="open"
        :title="t('text.workflow.guard.history.title', { name: workflow?.name || '' })"
        :width="drawerWidth"
        destroy-on-close
        data-testid="version-drawer"
        @close="$emit('close')"
    >
        <div class="space-y-4">
            <a-alert v-if="notice" :type="notice.tone" show-icon closable :message="notice.text" data-testid="version-notice" @close="notice = null" />

            <div v-if="loading && !versions.length" role="status" data-testid="version-loading">
                <a-skeleton active :paragraph="{ rows: 4 }" />
            </div>
            <a-alert v-else-if="loadError" type="error" show-icon :message="t('text.workflow.guard.history.load_failed')" data-testid="version-load-error">
                <template #action>
                    <a-button size="small" @click="load">{{ t('text.workflow.guard.retry') }}</a-button>
                </template>
            </a-alert>
            <template v-else>
                <p class="mb-3 mt-0 text-sm text-gray-600" data-testid="version-draft-state">{{ draftText }}</p>

                <a-empty v-if="!versions.length" :description="t('text.workflow.guard.history.empty')" data-testid="version-empty" />

                <ul v-else class="mb-0 list-none space-y-3 p-0">
                    <li
                        v-for="version in versions"
                        :key="version.uuid"
                        class="rounded-lg border border-solid border-slate-200 px-3 py-3"
                        :data-number="version.number"
                        data-testid="version-row"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-gray-800">{{ t('text.workflow.guard.version_n', { number: version.number }) }}</span>
                                <a-tag :color="versionStatus(version).color" class="!m-0">{{ t(versionStatus(version).key) }}</a-tag>
                            </div>
                            <span class="text-xs text-gray-500">{{ formatDate(version.created_at) }}</span>
                        </div>
                        <p class="m-0 mt-1 text-sm text-gray-600">
                            {{ version.created_by_name ? t('text.workflow.guard.history.by', { name: version.created_by_name }) : t('text.workflow.guard.history.by_system') }}
                            · {{ t('text.workflow.guard.history.steps', { count: version.steps || 0 }) }}
                        </p>
                        <p v-if="version.note" class="m-0 mt-1 break-words text-sm text-gray-700">{{ version.note }}</p>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <a-button size="small" :data-testid="`version-compare-${version.number}`" @click="toggleCompare(version)">
                                {{ compare.uuid === version.uuid ? t('text.workflow.guard.history.hide_changes') : t('text.workflow.guard.history.show_changes') }}
                            </a-button>
                            <a-button
                                v-if="canPublish && canRollbackTo(version)"
                                size="small"
                                :loading="rollingBack === version.uuid"
                                :disabled="Boolean(rollingBack) || stepUp.open.value"
                                :data-testid="`version-rollback-${version.number}`"
                                @click="askRollback(version)"
                            >
                                {{ t('text.workflow.guard.history.rollback') }}
                            </a-button>
                        </div>

                        <!-- What changed from this version to the draft or to another version. -->
                        <div v-if="compare.uuid === version.uuid" class="mt-3 space-y-2 rounded-md bg-slate-50 px-3 py-3" data-testid="version-diff">
                            <label class="block space-y-1">
                                <span class="text-xs text-gray-500">{{ t('text.workflow.guard.history.compare_with') }}</span>
                                <a-select
                                    v-model:value="compare.against"
                                    class="w-full"
                                    :options="againstOptions(version)"
                                    data-testid="version-against"
                                    @change="loadDiff"
                                />
                            </label>
                            <a-spin v-if="compare.loading" size="small" />
                            <a-alert v-else-if="compare.error" type="error" show-icon :message="t('text.workflow.guard.history.diff_failed')" />
                            <p v-else-if="!compare.rows.length" class="m-0 text-sm text-gray-600" data-testid="version-diff-same">
                                {{ t('text.workflow.guard.history.no_difference') }}
                            </p>
                            <ul v-else class="m-0 list-disc space-y-1 pl-5 text-sm">
                                <li v-for="row in compare.rows" :key="row.key" class="break-words" :class="toneClass[row.tone]" data-testid="version-diff-row">
                                    {{ row.text }}
                                    <span v-if="row.detail" class="block text-xs text-gray-500">{{ row.detail }}</span>
                                </li>
                            </ul>
                        </div>
                    </li>
                </ul>
            </template>
        </div>

        <StepUpDialog
            :open="stepUp.open.value"
            :purpose="PUBLISH_PURPOSE"
            :intro="t('text.workflow.guard.stepup.publish_intro')"
            @confirmed="stepUp.confirmed"
            @cancel="stepUp.cancelled"
        />
    </a-drawer>
</template>

<script setup>
import dayjs from 'dayjs'
import { Modal } from 'ant-design-vue'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStepUpRunner } from '@/libs/securityTokens'
import StepUpDialog from '../../../../components/security/step-up-dialog.vue'
import { PUBLISH_PURPOSE, fetchVersionDiff, fetchVersions, guardErrorCode, refusalProblems, rollbackVersion } from '../../../../workflow-guard-api'
import { canRollbackTo, diffRows, publishOutcome, publishProblems, versionStatus } from '../../../../workflow-guard-report'

defineOptions({ name: 'WorkflowVersionDrawer' })

const props = defineProps({
    open: { type: Boolean, default: false },
    workflow: { type: Object, default: null },
    /** step_code => step name of the draft, so sentences name steps the way the canvas does. */
    names: { type: Object, default: () => ({}) },
    canPublish: { type: Boolean, default: false },
})

/** `changed` after a rollback was accepted (went live or now waits): the lists outside must be read again. */
const emit = defineEmits(['close', 'changed'])

const { t } = useI18n()
const stepUp = useStepUpRunner({ purpose: PUBLISH_PURPOSE, lazy: true })

const versions = ref([])
const draft = ref(null)
const loading = ref(false)
const loadError = ref(false)
const notice = ref(null)
const rollingBack = ref('')
const compare = reactive({ uuid: '', against: 'draft', loading: false, error: false, rows: [] })

const drawerWidth = computed(() => (typeof window !== 'undefined' && window.innerWidth < 560 ? '100%' : 520))
const toneClass = { added: 'text-green-700', removed: 'text-red-700', changed: 'text-gray-800', muted: 'text-gray-500' }
const formatDate = (value) => (value ? dayjs(value).format('DD/MM/YYYY HH:mm') : '—')

const draftText = computed(() => {
    if (!draft.value || draft.value.published_number === null || draft.value.published_number === undefined) {
        return t('text.workflow.guard.draft.never_published')
    }
    return t(draft.value.differs ? 'text.workflow.guard.draft.differs_long' : 'text.workflow.guard.draft.same', { number: draft.value.published_number })
})

const againstOptions = (version) => [
    { value: 'draft', label: t('text.workflow.guard.history.the_draft') },
    ...versions.value
        .filter((row) => row.uuid !== version.uuid)
        .map((row) => ({ value: row.uuid, label: t('text.workflow.guard.version_n', { number: row.number }) })),
]

async function load() {
    const id = props.workflow?.uuid
    if (!id || loading.value) return
    loading.value = true
    loadError.value = false
    try {
        const body = await fetchVersions(id)
        if (id !== props.workflow?.uuid) return
        versions.value = body.versions
        draft.value = body.draft
    } catch (error) {
        loadError.value = true
    } finally {
        loading.value = false
    }
}

let diffToken = 0
async function loadDiff() {
    if (!compare.uuid) return
    const mine = ++diffToken
    compare.loading = true
    compare.error = false
    try {
        const diff = await fetchVersionDiff(props.workflow.uuid, compare.uuid, compare.against)
        if (mine !== diffToken) return
        compare.rows = diffRows(diff, { t, names: props.names })
    } catch (error) {
        if (mine !== diffToken) return
        compare.rows = []
        compare.error = true
    } finally {
        if (mine === diffToken) compare.loading = false
    }
}

function toggleCompare(version) {
    if (compare.uuid === version.uuid) {
        compare.uuid = ''
        return
    }
    compare.uuid = version.uuid
    compare.against = 'draft'
    compare.rows = []
    loadDiff()
}

function askRollback(version) {
    Modal.confirm({
        title: t('text.workflow.guard.history.rollback_title', { number: version.number }),
        content: t('text.workflow.guard.history.rollback_body', { number: version.number }),
        okText: t('text.workflow.guard.history.rollback'),
        cancelText: t('nav.b.cancel'),
        onOk: () => {
            // Not awaited: the confirmation closes and a sensitive rollback asks for the account confirmation next.
            rollback(version)
        },
    })
}

async function rollback(version) {
    if (rollingBack.value) return
    rollingBack.value = version.uuid
    notice.value = null
    try {
        const answer = await stepUp.run((grant) => rollbackVersion(props.workflow.uuid, version.uuid, grant))
        if (answer === undefined) return
        const outcome = publishOutcome(answer, { t, done: true })
        notice.value = { tone: outcome.tone, text: outcome.text }
        emit('changed', answer)
        rollingBack.value = ''
        await load()
    } catch (error) {
        const rows = publishProblems(refusalProblems(error), { t, names: props.names })
        const code = guardErrorCode(error)
        let text = t('text.workflow.guard.history.rollback_failed')
        if (rows.length) text = `${t('text.workflow.guard.publish.refused_title')} ${rows.map((row) => row.text).join(' ')}`
        else if (code === 'forbidden') text = t('text.workflow.guard.error.forbidden')
        else if (code === 'integrity_mismatch') text = t('text.workflow.guard.history.rollback_tampered')
        notice.value = { tone: 'error', text }
    } finally {
        rollingBack.value = ''
    }
}

// Two sources, compared one by one: a fresh copy of the same workflow (after a publish) must not reset the drawer.
watch(
    [() => props.open, () => props.workflow?.id],
    ([open]) => {
        if (!open) return
        versions.value = []
        draft.value = null
        notice.value = null
        compare.uuid = ''
        load()
    },
    { immediate: true }
)

defineExpose({ reload: load })
</script>
