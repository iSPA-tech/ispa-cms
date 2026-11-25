<template>
    <div class="max-w-5xl mx-auto space-y-4" data-testid="workflow-credentials-page">
        <section class="i-box space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <h3 class="m-0 text-sm font-semibold">{{ t('text.workflow_credential.title') }}</h3>
                    <p class="m-0 text-sm text-ink-muted">{{ t('text.workflow_credential.intro') }}</p>
                </div>
                <i-button variant="primary" :disabled="loading" data-testid="wc-add" @click="openCreate">
                    {{ t('text.workflow_credential.add') }}
                </i-button>
            </div>

            <a-alert v-if="notice" type="success" show-icon closable :message="notice" @close="notice = ''" />
            <a-alert v-if="actionError" type="error" show-icon closable :message="actionError" @close="actionError = ''" />

            <a-skeleton v-if="loading && !items.length" active :paragraph="{ rows: 2 }" />
            <a-alert v-else-if="loadError" type="error" show-icon :message="t('text.workflow_credential.load_failed')">
                <template #action>
                    <i-button size="sm" variant="default" @click="load">{{ t('text.workflow_credential.retry') }}</i-button>
                </template>
            </a-alert>
            <p v-else-if="!items.length" class="m-0 rounded-md bg-surface-sunken px-4 py-6 text-center text-sm text-ink-muted" data-testid="wc-empty">
                {{ t('text.workflow_credential.empty') }}
            </p>
            <div v-else class="overflow-x-auto">
                <a-table :columns="columns" :data-source="items" :pagination="false" row-key="uuid" size="small" data-testid="wc-table">
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'name'">
                            <div class="font-medium">{{ record.name }}</div>
                            <div v-if="record.description" class="text-xs text-ink-muted">{{ record.description }}</div>
                            <code class="text-xs">{{ record.binding }}</code>
                        </template>
                        <template v-else-if="column.key === 'type'">{{ typeName(record.type) }}</template>
                        <template v-else-if="column.key === 'used'">
                            {{ record.last_used_at ? formatDate(record.last_used_at) : t('text.workflow_credential.never_used') }}
                        </template>
                        <template v-else-if="column.key === 'test'">
                            <a-tag v-if="record.last_test_ok === true" color="green">{{ t('text.workflow_credential.test_ok') }}</a-tag>
                            <a-tag v-else-if="record.last_test_ok === false" color="red">{{ t('text.workflow_credential.test_failed') }}</a-tag>
                            <span v-else class="text-xs text-ink-muted">{{ t('text.workflow_credential.not_tested') }}</span>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <div class="flex flex-wrap gap-2">
                                <i-button size="sm" variant="default" :disabled="busy" @click="runTest(record)">{{ t('text.workflow_credential.test') }}</i-button>
                                <i-button size="sm" variant="default" :disabled="busy" @click="openEdit(record)">{{ t('text.workflow_credential.edit') }}</i-button>
                                <a-popconfirm :title="t('text.workflow_credential.delete_confirm')" @confirm="remove(record)">
                                    <i-button size="sm" variant="tone-danger" :disabled="busy">{{ t('text.workflow_credential.delete') }}</i-button>
                                </a-popconfirm>
                            </div>
                        </template>
                    </template>
                </a-table>
            </div>
        </section>

        <a-drawer
            :open="formOpen"
            :title="editing ? t('text.workflow_credential.edit_title') : t('text.workflow_credential.add_title')"
            :width="drawerWidth"
            destroy-on-close
            @close="closeForm"
        >
            <form class="space-y-3" data-testid="wc-form" @submit.prevent="save">
                <div class="space-y-1">
                    <label for="wc-name" class="block text-sm font-medium">{{ t('text.workflow_credential.name') }}</label>
                    <a-input id="wc-name" v-model:value="form.name" maxlength="64" />
                    <p class="m-0 text-xs text-ink-muted">{{ t('text.workflow_credential.name_help') }}</p>
                    <p v-if="errors.name" class="m-0 text-xs text-danger-ink">{{ errors.name }}</p>
                </div>
                <div class="space-y-1">
                    <label for="wc-type" class="block text-sm font-medium">{{ t('text.workflow_credential.type') }}</label>
                    <a-select id="wc-type" v-model:value="form.type" class="w-full" :options="typeOptions" :disabled="!!editing" />
                    <p v-if="errors.type" class="m-0 text-xs text-danger-ink">{{ errors.type }}</p>
                </div>
                <div class="space-y-1">
                    <label for="wc-description" class="block text-sm font-medium">{{ t('text.workflow_credential.description') }}</label>
                    <a-input id="wc-description" v-model:value="form.description" maxlength="255" />
                </div>
                <p v-if="editing" class="m-0 rounded-md bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                    {{ t('text.workflow_credential.keep_hint') }}
                </p>
                <div v-for="field in currentFields" :key="field" class="space-y-1">
                    <label :for="`wc-f-${field}`" class="block text-sm font-medium">{{ t(`text.workflow_credential.field.${field}`) }}</label>
                    <a-input-password
                        v-if="SECRET_FIELDS.includes(field)"
                        :id="`wc-f-${field}`"
                        v-model:value="form.payload[field]"
                        autocomplete="new-password"
                        :placeholder="editing && editing.fields_set?.[field] ? t('text.workflow_credential.saved_placeholder') : ''"
                    />
                    <a-input
                        v-else
                        :id="`wc-f-${field}`"
                        v-model:value="form.payload[field]"
                        autocomplete="off"
                        :placeholder="fieldPlaceholder(field)"
                    />
                    <p v-if="field === 'allowed_hosts'" class="m-0 text-xs text-ink-muted" data-testid="wc-hosts-help">
                        {{ t('text.workflow_credential.allowed_hosts_help') }}
                    </p>
                    <p v-if="errors[`payload.${field}`]" class="m-0 text-xs text-danger-ink">{{ errors[`payload.${field}`] }}</p>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <i-button variant="default" :disabled="saving" @click="closeForm">{{ t('text.workflow_credential.cancel') }}</i-button>
                    <i-button variant="primary" native-type="submit" :disabled="saving" :loading="saving">{{ t('text.workflow_credential.save') }}</i-button>
                </div>
            </form>
        </a-drawer>

        <http-hosts-card v-if="webmaster" />

        <!-- Adding, changing or deleting saved login details asks the account to confirm itself first. -->
        <step-up-dialog
            :open="stepUp.open.value"
            :purpose="CREDENTIAL_PURPOSE"
            :intro="t('text.workflow.guard.stepup.credential_intro')"
            @confirmed="stepUp.confirmed"
            @cancel="stepUp.cancelled"
        />
    </div>
</template>

<script setup>
/**
 * "Workflow credentials" (Settings > Automation). Bot tokens, mail servers, Facebook page keys and other secrets that
 * workflow steps use through `$bind:<name>`. Values are typed in once; the API never sends them back, so the edit form
 * starts empty and an empty field keeps the saved value. Needs `workflow_credential.manage` (route rule).
 * Create, change and delete need a fresh account confirmation (step-up grant `workflow_credential`): the dialog opens
 * before the request, and once more when the server says the grant is no longer valid.
 */
import { computed, defineComponent, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'
import { isWebmaster } from '@/hooks/useCan'
import { errorCode, grantConfig, useStepUpRunner } from '@/libs/securityTokens'
import HttpHostsCard from '../../components/workflow/http-hosts-card.vue'
import StepUpDialog from '../../components/security/step-up-dialog.vue'
import { CREDENTIAL_PURPOSE } from '../../workflow-guard-api'

defineComponent({ name: 'SettingWorkflowCredentials' })

const { t } = useI18n()
const webmaster = computed(() => isWebmaster())
const BASE = 'workflow/credentials'
const quiet = { silent: true }
const stepUp = useStepUpRunner({ purpose: CREDENTIAL_PURPOSE, wait: true })
const SECRET_FIELDS = ['token', 'password', 'access_token', 'value', 'api_key']

const items = ref([])
const types = ref({})
const loading = ref(false)
const loadError = ref(false)
const busy = ref(false)
const saving = ref(false)
const notice = ref('')
const actionError = ref('')

const formOpen = ref(false)
const editing = ref(null)
const form = reactive({ name: '', type: undefined, description: '', payload: {} })
const errors = ref({})

const drawerWidth = computed(() => (typeof window !== 'undefined' && window.innerWidth < 520 ? '100%' : 480))
const typeName = (type) => (type ? t(`text.workflow_credential.type_${type}`) : '')
const typeOptions = computed(() => Object.keys(types.value).map((key) => ({ value: key, label: typeName(key) })))
const currentFields = computed(() => (form.type ? types.value[form.type] || [] : []))
const formatDate = (iso) => new Date(iso).toLocaleString()
const fieldPlaceholder = (field) => {
    if (field === 'allowed_hosts') return 'api.example.com, *.example.org'
    return editing.value && editing.value.fields_set?.[field] ? t('text.workflow_credential.saved_placeholder') : ''
}

const columns = computed(() => [
    { key: 'name', title: t('text.workflow_credential.name') },
    { key: 'type', title: t('text.workflow_credential.type') },
    { key: 'used', title: t('text.workflow_credential.last_used') },
    { key: 'test', title: t('text.workflow_credential.last_test') },
    { key: 'actions', title: '' },
])

const firstErrors = (e) => {
    const raw = e?.response?.data?.errors || {}
    return Object.fromEntries(Object.entries(raw).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]))
}

/** The server still asked for a confirmation after one was given (grant expired on the way, or the cache is down). */
const stepUpFailed = (e) => ['step_up_required', 'step_up_unavailable'].includes(errorCode(e))

async function load() {
    if (loading.value) return
    loading.value = true
    loadError.value = false
    try {
        const body = (await ajax.get(BASE, quiet)).data || {}
        items.value = Array.isArray(body.data) ? body.data : []
        types.value = body.meta?.types || {}
    } catch (e) {
        loadError.value = true
    } finally {
        loading.value = false
    }
}

function reset(record) {
    errors.value = {}
    form.name = record?.name || ''
    form.type = record?.type
    form.description = record?.description || ''
    // Allowed hosts are not secret: the form shows the saved list so it can be changed.
    form.payload = record?.allowed_hosts ? { allowed_hosts: record.allowed_hosts } : {}
}

function openCreate() {
    editing.value = null
    reset(null)
    formOpen.value = true
}

function openEdit(record) {
    editing.value = record
    reset(record)
    formOpen.value = true
}

function closeForm() {
    formOpen.value = false
    form.payload = {}
}

async function save() {
    if (saving.value || stepUp.open.value) return
    saving.value = true
    errors.value = {}
    actionError.value = ''
    const payload = Object.fromEntries(Object.entries(form.payload).filter(([, v]) => v !== undefined && v !== ''))
    // An unchanged host list is not a change (no "credential changed" alert for it).
    if (editing.value && payload.allowed_hosts === editing.value.allowed_hosts) delete payload.allowed_hosts
    try {
        const target = editing.value
        const sent = await stepUp.run((grant) =>
            target
                ? ajax.put(`${BASE}/${encodeURIComponent(target.uuid)}`, { name: form.name, description: form.description || null, payload }, grantConfig(grant))
                : ajax.post(BASE, { name: form.name, type: form.type, description: form.description || null, payload }, grantConfig(grant))
        )
        // The confirmation was closed: nothing was saved and the form stays as typed.
        if (sent === undefined) {
            saving.value = false
            return
        }
        notice.value = t('text.workflow_credential.saved')
        closeForm()
        saving.value = false
        await load()
    } catch (e) {
        errors.value = firstErrors(e)
        if (!Object.keys(errors.value).length) actionError.value = t(stepUpFailed(e) ? 'text.workflow.guard.error.stepup_needed' : 'text.workflow_credential.save_failed')
        saving.value = false
    }
}

async function remove(record) {
    if (busy.value || stepUp.open.value) return
    busy.value = true
    actionError.value = ''
    try {
        const sent = await stepUp.run((grant) => ajax.delete(`${BASE}/${encodeURIComponent(record.uuid)}`, grantConfig(grant)))
        if (sent === undefined) return
        notice.value = t('text.workflow_credential.deleted')
        await load()
    } catch (e) {
        actionError.value = t(stepUpFailed(e) ? 'text.workflow.guard.error.stepup_needed' : 'text.workflow_credential.delete_failed')
    } finally {
        busy.value = false
    }
}

async function runTest(record) {
    if (busy.value) return
    busy.value = true
    notice.value = ''
    actionError.value = ''
    try {
        const result = (await ajax.post(`${BASE}/${encodeURIComponent(record.uuid)}/test`, {}, quiet)).data?.data || {}
        if (result.status === 'ok') notice.value = t('text.workflow_credential.test_ok_notice', { name: record.name })
        else if (result.status === 'not_testable') notice.value = t('text.workflow_credential.not_testable')
        else actionError.value = t('text.workflow_credential.test_failed_notice', { name: record.name, reason: result.message || '' })
        await load()
    } catch (e) {
        actionError.value = t('text.workflow_credential.test_failed_notice', { name: record.name, reason: '' })
    } finally {
        busy.value = false
    }
}

onMounted(load)
</script>
