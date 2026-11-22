import { Modal } from 'ant-design-vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const clone = (value) => (Array.isArray(value) ? [...value] : value)

/** Copies the protected keys of a form so they can be restored later. */
export const captureKeys = (form, keys) => Object.fromEntries(keys.map((k) => [k, clone(form[k])]))

/** Restores protected keys whose value changed since capture; returns true if any was reverted. */
export const restoreKeys = (form, saved) => {
    let changed = false
    for (const [key, value] of Object.entries(saved)) {
        if (JSON.stringify(form[key]) !== JSON.stringify(value)) {
            form[key] = clone(value)
            changed = true
        }
    }
    return changed
}

/**
 * Unsaved-changes guard shared by the AI Runtime drawers.
 * Every close path (Esc, X, Cancel, mask click) goes through requestClose().
 *
 * @param {object} form Reactive form object compared against its snapshot.
 * @param {() => void} close Closes the drawer unconditionally.
 * @param {string[]} protectedKeys Fields ant-design clears on Esc (auto-complete / tags); kept as they were.
 */
export function useDrawerGuard(form, close, protectedKeys = []) {
    const { t } = useI18n()
    const initial = ref('')
    const dropdownOpen = ref(false)
    let escWhileDropdownOpen = false

    const dirty = computed(() => JSON.stringify(form) !== initial.value)
    const snapshot = () => {
        initial.value = JSON.stringify(form)
        dropdownOpen.value = false
    }

    const requestClose = () => {
        if (!dirty.value) {
            close()
            return
        }
        Modal.confirm({
            title: t('text.ai_runtime.guard.title'),
            okText: t('text.ai_runtime.guard.discard'),
            cancelText: t('text.ai_runtime.guard.keep_editing'),
            onOk: close,
        })
    }

    // Capture runs before the select handles Esc; remember whether its dropdown was open.
    const onEscCapture = () => {
        escWhileDropdownOpen = dropdownOpen.value
        if (!escWhileDropdownOpen || !protectedKeys.length) return
        // ant-design clears the search value on Esc; put the previous value back afterwards.
        const saved = captureKeys(form, protectedKeys)
        setTimeout(() => restoreKeys(form, saved), 0)
        setTimeout(() => restoreKeys(form, saved), 80)
    }
    // Bubble phase: keep the drawer from seeing an Esc that only closed a dropdown.
    const onEscBubble = (event) => {
        if (escWhileDropdownOpen) {
            event.stopPropagation()
            escWhileDropdownOpen = false
        }
    }
    const onDropdownChange = (open) => {
        dropdownOpen.value = Boolean(open)
    }

    return { dirty, snapshot, requestClose, onEscCapture, onEscBubble, onDropdownChange }
}
