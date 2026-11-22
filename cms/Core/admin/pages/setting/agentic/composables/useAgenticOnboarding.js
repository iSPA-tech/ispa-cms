import { computed, ref } from 'vue'
import { useStorage } from '@vueuse/core'
import { CACHE_KEY_ACCOUNT_DATA } from '@/config'

/**
 * Manages first-time wizard visibility via localStorage.
 * Used by AgenticDashboard to show/hide the onboarding wizard.
 * Storage key is scoped to the current user ID to avoid cross-user leakage.
 */
export function useAgenticOnboarding() {
    const accountData = useStorage(CACHE_KEY_ACCOUNT_DATA, {})
    const userId = computed(() => accountData.value?.id || 'anonymous')
    const storageKey = computed(() => `agentic_onboarding_done_${userId.value}`)

    const showWizard = ref(!safeGetItem(storageKey.value))

    /**
     * Safely read from localStorage. Returns null on failure
     * (e.g. private browsing mode or storage quota exceeded).
     */
    function safeGetItem(key) {
        try {
            return localStorage.getItem(key)
        } catch {
            // Private browsing or storage unavailable
            return null
        }
    }

    /**
     * Safely write to localStorage. Silently fails when storage is unavailable.
     */
    function safeSetItem(key, value) {
        try {
            localStorage.setItem(key, value)
        } catch {
            // Private browsing or storage unavailable
        }
    }

    /**
     * Safely remove from localStorage. Silently fails when storage is unavailable.
     */
    function safeRemoveItem(key) {
        try {
            localStorage.removeItem(key)
        } catch {
            // Private browsing or storage unavailable
        }
    }

    function completeOnboarding() {
        safeSetItem(storageKey.value, '1')
        showWizard.value = false
    }

    function resetOnboarding() {
        safeRemoveItem(storageKey.value)
        showWizard.value = true
    }

    return { showWizard, completeOnboarding, resetOnboarding }
}
