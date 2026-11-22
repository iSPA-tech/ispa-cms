<template>
    <div class="i-box flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-slate-500">{{ $t('text.ai_runtime.header.subtitle') }}</p>
        <div class="flex flex-wrap items-center gap-2">
            <span
                v-if="rt.spend.value"
                :class="chipClass"
                class="rounded-full border px-3 py-1 text-xs font-medium"
                data-testid="spend-chip"
            >
                {{ rt.spend.value.text }}
                <template v-if="rt.spend.value.level === 'danger'"> · {{ $t('text.ai_runtime.spend.reached') }}</template>
                <template v-else-if="rt.spend.value.level === 'warning'">
                    · {{ $t('text.ai_runtime.spend.near') }}</template
                >
            </span>
            <i-button
                v-if="!rt.paused.value"
                :disabled="rt.killBusy.value"
                :loading="rt.killBusy.value"
                variant="danger"
                @click="pause"
            >
                {{ $t('text.ai_runtime.kill.pause') }}
            </i-button>
            <i-button
                v-else
                :disabled="rt.killBusy.value"
                :loading="rt.killBusy.value"
                variant="primary"
                @click="rt.setKillSwitch(false)"
            >
                {{ $t('text.ai_runtime.kill.resume') }}
            </i-button>
        </div>
    </div>
</template>

<script setup>
import { Modal } from 'ant-design-vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAiRuntime } from './useAiRuntime'

const rt = useAiRuntime()
const { t } = useI18n()

// State is also spelled out in the text suffix, so color is never the only signal.
const chipClass = computed(
    () =>
        ({
            ok: 'border-slate-200 bg-slate-50 text-slate-700',
            warning: 'border-amber-300 bg-amber-50 text-amber-800',
            danger: 'border-red-300 bg-red-50 text-red-700',
        })[rt.spend.value?.level || 'ok']
)

const pause = () => {
    if (rt.killBusy.value) return
    Modal.confirm({
        title: t('text.ai_runtime.kill.confirm_title'),
        content: t('text.ai_runtime.kill.confirm_content'),
        okText: t('text.ai_runtime.kill.pause'),
        cancelText: t('text.ai_runtime.common.cancel'),
        okType: 'danger',
        onOk: () => rt.setKillSwitch(true),
    })
}
</script>
