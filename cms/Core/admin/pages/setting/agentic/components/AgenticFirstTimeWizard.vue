<template>
    <a-modal
        v-model:open="showWizard"
        :title="null"
        :footer="null"
        :closable="true"
        :width="480"
        centered
    >
        <!-- Step 1: What is an AI Agent? -->
        <div v-if="currentStep === 1" class="py-6 text-center">
            <div class="mb-6 text-5xl">🤖</div>
            <h2 class="mb-4 text-xl font-semibold text-ink">
                {{ t('text.agentic.onboarding.step1_title') }}
            </h2>
            <p class="mx-auto mb-3 max-w-sm text-sm leading-relaxed text-ink-muted">
                {{ t('text.agentic.onboarding.step1_body') }}
            </p>
            <p class="mx-auto max-w-sm text-sm leading-relaxed text-ink-muted">
                {{ t('text.agentic.onboarding.step1_approval_note') }}
            </p>
        </div>

        <!-- Step 2: How to run tasks -->
        <div v-if="currentStep === 2" class="py-6 text-center">
            <div class="mb-6 text-5xl">🚀</div>
            <h2 class="mb-4 text-xl font-semibold text-ink">
                {{ t('text.agentic.onboarding.step2_title') }}
            </h2>
            <div class="mx-auto max-w-sm space-y-3 text-left text-sm leading-relaxed text-ink-muted">
                <div class="flex gap-3">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-info-soft text-xs font-semibold text-info-ink">1</span>
                    <span>{{ t('text.agentic.onboarding.step2_way1') }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-info-soft text-xs font-semibold text-info-ink">2</span>
                    <span>{{ t('text.agentic.onboarding.step2_way2') }}</span>
                </div>
            </div>
        </div>

        <!-- Step 3: How to review proposals -->
        <div v-if="currentStep === 3" class="py-6 text-center">
            <div class="mb-6 text-5xl">✅</div>
            <h2 class="mb-4 text-xl font-semibold text-ink">
                {{ t('text.agentic.onboarding.step3_title') }}
            </h2>
            <div class="mx-auto max-w-sm space-y-2 text-left text-sm leading-relaxed text-ink-muted">
                <p>{{ t('text.agentic.onboarding.step3_inbox') }}</p>
                <p>{{ t('text.agentic.onboarding.step3_diff') }}</p>
                <p>{{ t('text.agentic.onboarding.step3_actions') }}</p>
            </div>
        </div>

        <!-- Custom footer -->
        <div class="flex items-center justify-between border-t border-border px-2 pt-4">
            <div>
                <a-button
                    v-if="currentStep === 1"
                    type="text"
                    size="small"
                    @click="skipWizard"
                >
                    {{ t('text.agentic.onboarding.skip') }}
                </a-button>
                <a-button
                    v-else
                    size="small"
                    @click="currentStep--"
                >
                    {{ t('text.agentic.onboarding.back') }}
                </a-button>
            </div>

            <span class="text-xs text-ink-faint">{{ currentStep }}/3</span>

            <a-button
                type="primary"
                size="small"
                @click="nextStep"
            >
                {{ currentStep === 3
                    ? t('text.agentic.onboarding.get_started')
                    : t('text.agentic.onboarding.next')
                }}
            </a-button>
        </div>
    </a-modal>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgenticOnboarding } from '../composables/useAgenticOnboarding.js'

const { t } = useI18n()
const { showWizard, completeOnboarding } = useAgenticOnboarding()

const currentStep = ref(1)

function nextStep() {
    if (currentStep.value >= 3) {
        completeOnboarding()
        return
    }
    currentStep.value++
}

function skipWizard() {
    completeOnboarding()
}
</script>
