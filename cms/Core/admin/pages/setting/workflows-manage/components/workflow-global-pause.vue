<!--
  Pause of all workflows. While it is on, a banner says so on the workflows page. A Webmaster turns it on (after a clear
  confirmation) and off here; the server answers the state only to Webmasters, so other accounts see nothing.
-->
<template>
    <div v-if="state" data-testid="global-pause">
        <a-alert v-if="state.paused" type="error" show-icon :message="t('text.workflow.guard.pause_all.banner_title')" data-testid="global-pause-banner">
            <template #description>
                <div class="space-y-2">
                    <p class="m-0 text-sm">{{ t('text.workflow.guard.pause_all.banner_body') }}</p>
                    <p v-if="state.forced_by_server" class="m-0 text-sm" data-testid="global-pause-forced">{{ t('text.workflow.guard.pause_all.forced') }}</p>
                    <p v-if="errorKey" class="m-0 text-sm text-red-600">{{ t(errorKey) }}</p>
                    <a-button
                        v-if="webmaster && !state.forced_by_server"
                        size="small"
                        type="primary"
                        :loading="busy"
                        data-testid="global-pause-off"
                        @click="askResume"
                    >
                        {{ t('text.workflow.guard.pause_all.turn_off') }}
                    </a-button>
                </div>
            </template>
        </a-alert>
        <div v-else-if="webmaster" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-solid border-slate-200 bg-white px-3 py-2">
            <div class="min-w-0 text-sm text-gray-600">
                {{ t('text.workflow.guard.pause_all.hint') }}
                <span v-if="errorKey" class="block text-red-600">{{ t(errorKey) }}</span>
            </div>
            <a-button danger :loading="busy" data-testid="global-pause-on" @click="askPause">
                {{ t('text.workflow.guard.pause_all.turn_on') }}
            </a-button>
        </div>
    </div>
</template>

<script setup>
import { Modal } from 'ant-design-vue'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isWebmaster } from '@/hooks/useCan'
import { fetchGlobalPause, setGlobalPause } from '../../../../workflow-guard-api'

defineOptions({ name: 'WorkflowGlobalPause' })

/** `changed` carries the new state (`paused`) so the page can say why runs are blocked. */
const emit = defineEmits(['changed'])

const { t } = useI18n()
const webmaster = computed(() => isWebmaster())
const state = ref(null)
const busy = ref(false)
const errorKey = ref('')

async function load() {
    // The server answers the state to Webmasters only; other accounts are not asked (it would be a refused request).
    if (!webmaster.value) {
        state.value = null
        return
    }
    try {
        state.value = await fetchGlobalPause()
        emit('changed', Boolean(state.value?.paused))
    } catch (error) {
        // The state could not be read: no banner and no switch.
        state.value = null
    }
}

async function apply(paused) {
    if (busy.value) return
    busy.value = true
    errorKey.value = ''
    try {
        state.value = await setGlobalPause(paused)
        emit('changed', Boolean(state.value?.paused))
    } catch (error) {
        errorKey.value = 'text.workflow.guard.pause_all.failed'
    } finally {
        busy.value = false
    }
}

function askPause() {
    Modal.confirm({
        title: t('text.workflow.guard.pause_all.confirm_title'),
        content: t('text.workflow.guard.pause_all.confirm_body'),
        okText: t('text.workflow.guard.pause_all.turn_on'),
        okButtonProps: { danger: true },
        cancelText: t('nav.b.cancel'),
        onOk: () => apply(true),
    })
}

function askResume() {
    Modal.confirm({
        title: t('text.workflow.guard.pause_all.resume_title'),
        content: t('text.workflow.guard.pause_all.resume_body'),
        okText: t('text.workflow.guard.pause_all.turn_off'),
        cancelText: t('nav.b.cancel'),
        onOk: () => apply(false),
    })
}

// The account may arrive after the page: read the state as soon as it is known to be a Webmaster.
watch(webmaster, load, { immediate: true })
defineExpose({ reload: load })
</script>
