<template>
    <div data-testid="revoke-all">
        <i-button variant="danger" size="md" :disabled="busy" data-testid="revoke-all-open" @click="openDialog">
            {{ triggerLabel }}
        </i-button>

        <a-modal
            :open="visible"
            :title="triggerLabel"
            :footer="null"
            :mask-closable="false"
            :destroy-on-close="true"
            width="460px"
            data-testid="revoke-all-dialog"
            @cancel="close"
        >
            <form class="space-y-3" autocomplete="off" @submit.prevent="submit">
                <a-alert type="warning" show-icon :message="t('text.security_tokens.revoke_all.warning')" />

                <div class="text-sm" data-testid="revoke-all-summary">
                    <div v-if="countsLoading" role="status"><a-skeleton active :paragraph="{ rows: 1 }" /></div>
                    <template v-else-if="countsError">
                        <span class="text-red-600">{{ t('text.security_tokens.revoke_all.counts_error') }}</span>
                    </template>
                    <template v-else>
                        <p class="m-0 font-medium" data-testid="revoke-all-total">
                            {{ t('text.security_tokens.revoke_all.total', { count: total }) }}
                        </p>
                        <ul v-if="byApi.length" class="m-0 mt-1 list-disc pl-5">
                            <li v-for="row in byApi" :key="row.key">
                                {{ row.label }}: {{ row.count }}
                            </li>
                        </ul>
                    </template>
                </div>

                <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.revoke_all.spared') }}</p>

                <label class="block text-sm font-medium" for="revoke-all-word">
                    {{ t('text.security_tokens.revoke_all.type_word', { word: CONFIRM_WORD }) }}
                </label>
                <input
                    id="revoke-all-word"
                    v-model="typed"
                    type="text"
                    autocomplete="off"
                    class="ant-input w-full"
                    :disabled="busy"
                    data-testid="revoke-all-word"
                />

                <a-alert v-if="errorKey" type="error" show-icon :message="t(errorKey)" data-testid="revoke-all-error" />

                <div class="flex flex-wrap justify-end gap-2">
                    <i-button size="md" :disabled="busy" data-testid="revoke-all-cancel" @click="close">
                        {{ t('text.security_tokens.revoke_all.cancel') }}
                    </i-button>
                    <i-button
                        variant="danger"
                        size="md"
                        native-type="submit"
                        :loading="busy"
                        :disabled="busy || typed !== CONFIRM_WORD"
                        data-testid="revoke-all-confirm"
                    >
                        {{ t('text.security_tokens.revoke_all.confirm') }}
                    </i-button>
                </div>
            </form>
        </a-modal>

        <step-up-dialog :open="stepUp.open.value" @confirmed="stepUp.confirmed" @cancel="stepUp.cancelled" />
    </div>
</template>

<script setup>
/**
 * Webmaster-only bulk revoke. `scope`: `all` (every API, every account), `audience` (one API, needs `audience`) or `user`
 * (one account, needs `userUuid` and the number of its tokens in `count`). The dialog states how many tokens and which
 * APIs it ends, asks for the typed word, then for the step-up confirmation (the grant is used up). It never ends the
 * session in use: the API spares that token, and the text says so.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
    AUDIENCES,
    CONFIRM_WORD,
    errorCode,
    previewTokenSettings,
    revokeAll,
    revokeAudience,
    revokeUser,
    useStepUpRunner,
} from '@/libs/securityTokens'
import StepUpDialog from './step-up-dialog.vue'

const props = defineProps({
    /** `all`, `audience` or `user`. */
    scope: { type: String, default: 'all', validator: (v) => ['all', 'audience', 'user'].includes(v) },
    /** API family for the `audience` scope. */
    audience: { type: String, default: '' },
    /** Account uuid for the `user` scope. */
    userUuid: { type: String, default: '' },
    /** Number of live tokens of that account (`user` scope). */
    count: { type: Number, default: 0 },
})
const emit = defineEmits(['done'])

const { t } = useI18n()
const stepUp = useStepUpRunner()

const visible = ref(false)
const typed = ref('')
const busy = ref(false)
const errorKey = ref('')
const countsLoading = ref(false)
const countsError = ref(false)
const previewBody = ref(null)
let disposed = false

const triggerLabel = computed(() => {
    if (props.scope === 'user') return t('text.security_tokens.revoke_all.trigger_user')
    if (props.scope === 'audience') {
        return t('text.security_tokens.revoke_all.trigger_audience', { api: t(`text.security_tokens.audience.${props.audience}`) })
    }
    return t('text.security_tokens.revoke_all.trigger_all')
})

/** Counts per API from the preview (live tokens of the saved settings). */
const byApi = computed(() => {
    if (props.scope === 'user') return []
    const body = previewBody.value || {}
    const rows = AUDIENCES.filter((a) => props.scope === 'all' || a === props.audience).map((a) => ({
        key: a,
        label: t(`text.security_tokens.audience.${a}`),
        count: Number(body.audiences?.[a]?.live ?? 0),
    }))
    if (props.scope === 'all') {
        rows.push({ key: 'legacy', label: t('text.security_tokens.audience.legacy'), count: Number(body.without_audience ?? 0) })
    }
    return rows.filter((row) => row.count > 0)
})

const total = computed(() => (props.scope === 'user' ? props.count : byApi.value.reduce((sum, row) => sum + row.count, 0)))

const wipe = () => {
    typed.value = ''
    errorKey.value = ''
}

const loadCounts = async () => {
    if (props.scope === 'user') return
    countsLoading.value = true
    countsError.value = false
    try {
        const body = (await previewTokenSettings({}))?.data || {}
        if (!disposed) previewBody.value = body
    } catch {
        if (!disposed) countsError.value = true
    } finally {
        if (!disposed) countsLoading.value = false
    }
}

const openDialog = () => {
    wipe()
    visible.value = true
    loadCounts()
}

const close = () => {
    if (busy.value) return
    visible.value = false
    wipe()
    stepUp.cancelled()
}

/** The API call for the scope, with the typed word. */
const callFor = (grant) => {
    if (props.scope === 'user') return revokeUser(props.userUuid, typed.value, grant)
    if (props.scope === 'audience') return revokeAudience(props.audience, typed.value, grant)
    return revokeAll(typed.value, grant)
}

/** Send once: the guard stops a double click; the confirmation dialog opens when no grant is held. */
const submit = () => {
    if (busy.value || stepUp.open.value || typed.value !== CONFIRM_WORD) return undefined
    const action = async (grant) => {
        busy.value = true
        errorKey.value = ''
        try {
            const body = await callFor(grant)
            if (disposed) return
            visible.value = false
            wipe()
            emit('done', { revoked: body?.data?.revoked ?? 0 })
        } catch (err) {
            if (errorCode(err) === 'step_up_required') throw err
            if (!disposed) {
                const code = errorCode(err)
                errorKey.value =
                    code === 'step_up_locked' ? 'text.security_tokens.list.action_locked' : 'text.security_tokens.list.action_error'
            }
        } finally {
            if (!disposed) busy.value = false
        }
    }
    return stepUp.run(action, { consume: true })
}

onBeforeUnmount(() => {
    disposed = true
    typed.value = ''
    stepUp.cancelled()
})
</script>
