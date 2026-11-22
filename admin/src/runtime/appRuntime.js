/**
 * appRuntime - MVP Locale Runtime Singleton
 *
 * Centralizes locale state to fix HIGH-risk bugs where globalLocale (Ref)
 * was compared as primitive string.
 *
 * Usage:
 *   import { useAppRuntime } from '@/runtime'
 *   const { locale, antLocale, toggleLocale, formatLocalizedDateTime, languages, globalSettings } = useAppRuntime()
 */
import { computed, ref, watch } from 'vue'
import dayjs from 'dayjs'
import localizedFormat from 'dayjs/plugin/localizedFormat'
import relativeTime from 'dayjs/plugin/relativeTime'
// Registers the 'vi' dayjs locale. Ant pickers read month/weekday names from dayjs by the locale code
// of the active antLocale, so without this import vi falls back to English names.
import 'dayjs/locale/vi'
import viVN from 'ant-design-vue/es/locale/vi_VN'
import enUS from 'ant-design-vue/es/locale/en_US'
import { cookie, COOKIE_KEY_LOCALE, defaultLocale, i18n, setI18nLanguage } from '@/config'

// Extend dayjs with localized format plugin
dayjs.extend(localizedFormat)
// Extend dayjs with relative time so fromNow()/from()/toNow() work in every page
dayjs.extend(relativeTime)

/**
 * Point the global dayjs locale at the given admin language.
 * Unknown values fall back to 'en'.
 * @param {string} lang - Admin language code ('vi' | 'en')
 */
function applyDayjsLocale(lang) {
    dayjs.locale(['vi', 'en'].includes(lang) ? lang : 'en')
}

/**
 * Normalize locale value to valid 'vi' | 'en'
 */
function normalizeLocale(value) {
    if (value === 'vi' || value === 'en') {
        return value
    }
    // Fallback chain: defaultLocale -> 'vi'
    if (defaultLocale === 'vi' || defaultLocale === 'en') {
        return defaultLocale
    }
    return 'vi'
}

/**
 * Create the runtime singleton
 */
function createAppRuntime() {
    // Initialize locale from cookie
    const initialLocale = normalizeLocale(cookie.get(COOKIE_KEY_LOCALE, defaultLocale))
    const locale = ref(initialLocale)

    // Keep the global dayjs locale in sync with the locale ref. The ref can be written directly
    // (bypassing setLocale), and 'sync' flush makes the change visible immediately.
    applyDayjsLocale(initialLocale)
    watch(locale, applyDayjsLocale, { flush: 'sync' })

    // Languages ref - will be bound by App.vue
    // Fallback to empty ref with data structure if not bound
    let languagesRef = ref({ data: [] })

    /**
     * Bind languages ref from App.vue
     * @param {Ref} langRef - The languages ref created in App.vue
     */
    const bindLanguages = (langRef) => {
        languagesRef = langRef
    }

    /**
     * Get languages ref (getter to always return current bound ref)
     */
    const getLanguages = () => languagesRef

    // GlobalSettings ref - will be bound by App.vue
    let globalSettingsRef = ref({})

    /**
     * Bind globalSettings ref from App.vue
     * @param {Ref} settingsRef - The globalSettings ref created in App.vue
     */
    const bindGlobalSettings = (settingsRef) => {
        globalSettingsRef = settingsRef
    }

    /**
     * Get globalSettings ref (getter to always return current bound ref)
     */
    const getGlobalSettings = () => globalSettingsRef

    /**
     * Set locale and sync with cookie + i18n
     */
    const setLocale = async (next) => {
        const normalized = normalizeLocale(next)
        cookie.set(COOKIE_KEY_LOCALE, normalized)
        locale.value = normalized
        await setI18nLanguage(i18n, normalized)
    }

    /**
     * Toggle between vi <-> en
     */
    const toggleLocale = () => {
        const next = locale.value === 'vi' ? 'en' : 'vi'
        setLocale(next)
    }

    /**
     * Computed Ant Design locale object
     * Reactive: updates when locale changes
     */
    const antLocale = computed(() => {
        return locale.value === 'vi' ? viVN : enUS
    })

    /**
     * Format date/time using current locale
     * @param {string|Date|dayjs.Dayjs} dateInput
     * @returns {string} Formatted date string
     */
    const formatLocalizedDateTime = (dateInput) => {
        // Global dayjs locale already follows the locale ref; re-apply for safety
        applyDayjsLocale(locale.value)
        return dayjs(dateInput).format('LLLL')
    }

    // SignOut function - will be bound by App.vue
    let signOutFn = () => {
        console.warn('signOut not bound')
    }

    /**
     * Bind signOut function from App.vue
     * @param {Function} fn - The signOut function
     */
    const bindSignOut = (fn) => {
        signOutFn = fn
    }

    /**
     * Get signOut function
     */
    const getSignOut = () => signOutFn

    // FetchAndCacheGlobalSettings function - will be bound by App.vue
    let fetchAndCacheGlobalSettingsFn = async () => {
        console.warn('fetchAndCacheGlobalSettings not bound')
    }

    /**
     * Bind fetchAndCacheGlobalSettings function from App.vue
     * @param {Function} fn
     */
    const bindFetchAndCacheGlobalSettings = (fn) => {
        fetchAndCacheGlobalSettingsFn = fn
    }

    /**
     * Get fetchAndCacheGlobalSettings function
     */
    const getFetchAndCacheGlobalSettings = () => fetchAndCacheGlobalSettingsFn

    return {
        locale,
        setLocale,
        toggleLocale,
        antLocale,
        formatLocalizedDateTime,
        bindLanguages,
        getLanguages,
        bindGlobalSettings,
        getGlobalSettings,
        bindSignOut,
        getSignOut,
        bindFetchAndCacheGlobalSettings,
        getFetchAndCacheGlobalSettings,
    }
}

// Singleton instance
const appRuntime = createAppRuntime()

/**
 * Hook to access the runtime singleton
 * @returns {ReturnType<typeof createAppRuntime> & { languages: Ref }}
 */
export function useAppRuntime() {
    return {
        ...appRuntime,
        // Expose languages as a getter result so it returns the current bound ref
        get languages() {
            return appRuntime.getLanguages()
        },
        // Expose globalSettings as a getter result
        get globalSettings() {
            return appRuntime.getGlobalSettings()
        },
        // Expose signOut as a getter result
        get signOut() {
            return appRuntime.getSignOut()
        },
        // Expose fetchAndCacheGlobalSettings as a getter result
        get fetchAndCacheGlobalSettings() {
            return appRuntime.getFetchAndCacheGlobalSettings()
        },
    }
}

// Also export the singleton directly for advanced use cases
export { appRuntime }
