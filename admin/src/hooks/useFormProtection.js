import { ref, watch, toRaw, onBeforeUnmount, unref, isRef } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { cloneDeep, isEqual } from 'lodash-es'

/**
 * Form protection composable for unsaved changes detection and submit protection
 * Based on Post form patterns (create.vue / update.vue)
 *
 * @param {Ref|Reactive|ComputedRef} target - Reactive reference to form data (Ref, reactive, or computed)
 * @param {Object} options - Configuration options
 * @param {Boolean} options.isEditMode - Whether form is in edit mode
 * @param {Function} options.onDiscard - Callback when discarding changes (receives baseline data)
 * @param {Function} options.onSubmitSuccess - Callback after successful submit
 * @returns {Object} Form protection state and methods
 */
export function useFormProtection(target, options = {}) {
    const { isEditMode = false, onDiscard = null, onSubmitSuccess = null } = options

    const router = useRouter()
    const { t } = useI18n()

    const isFormChanged = ref(false)
    const isSubmitting = ref(false)
    const baselineData = ref(null)
    const baselineReady = ref(false)

    /**
     * Get current snapshot of the target (handles Ref, reactive, computed)
     */
    const getSnapshot = () => {
        const value = unref(target)
        return cloneDeep(toRaw(value))
    }

    /**
     * Capture current form state as baseline for change detection
     * Call this after initial data load in edit mode
     */
    const resetBaseline = () => {
        baselineData.value = getSnapshot()
        isFormChanged.value = false
        baselineReady.value = true
    }

    /**
     * Watch for form changes and compare against baseline
     */
    watch(
        () => {
            // Watch the unwrapped value for changes
            const value = unref(target)
            // Return a deep clone to trigger reactivity on nested changes
            return cloneDeep(toRaw(value))
        },
        (currentSnapshot) => {
            if (!baselineReady.value) {
                isFormChanged.value = false
                return
            }

            isFormChanged.value = !isEqual(currentSnapshot, baselineData.value)
        },
        { deep: true, immediate: true }
    )

    /**
     * Discard changes and navigate back
     * Shows confirmation if form has unsaved changes
     */
    const discardChanges = () => {
        if (!isFormChanged.value) {
            router.back()
            return
        }

        if (!confirm(t('mgs.confirm.discard_confirm'))) {
            return
        }

        // Restore baseline data via callback
        if (onDiscard && baselineData.value) {
            onDiscard(cloneDeep(baselineData.value))
        }

        isFormChanged.value = false
        router.back()
    }

    /**
     * Wrap submit function with loading state and baseline reset
     * Prevents multiple simultaneous submissions
     *
     * @param {Function} submitFn - Async function that performs the actual submit
     */
    const handleSubmit = async (submitFn) => {
        if (isSubmitting.value) return

        isSubmitting.value = true
        try {
            await submitFn()
            resetBaseline()
            if (onSubmitSuccess) {
                onSubmitSuccess()
            }
        } finally {
            isSubmitting.value = false
        }
    }

    /**
     * Router navigation guard
     * Use with onBeforeRouteLeave in component
     */
    const beforeRouteLeave = (to, from, next) => {
        if (!isFormChanged.value) {
            next()
            return
        }

        if (confirm(t('mgs.confirm.leave_confirm'))) {
            next()
        } else {
            next(false)
        }
    }

    /**
     * Browser beforeunload handler
     * Warns user when trying to close/refresh with unsaved changes
     */
    const handleBeforeUnload = (e) => {
        if (isFormChanged.value) {
            e.preventDefault()
            e.returnValue = ''
        }
    }

    window.addEventListener('beforeunload', handleBeforeUnload)

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', handleBeforeUnload)
    })

    return {
        isFormChanged,
        isSubmitting,
        resetBaseline,
        discardChanges,
        handleSubmit,
        beforeRouteLeave,
    }
}
