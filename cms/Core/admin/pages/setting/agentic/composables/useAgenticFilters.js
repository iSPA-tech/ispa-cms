import { reactive } from 'vue'

/**
 * All filter reactive objects for Agentic CMS pages.
 * Shared between Dashboard, Inbox, AdminConfig.
 */
export function useAgenticFilters() {
    const definitionFilters = reactive({
        search: '',
        family: undefined,
        capabilityClass: undefined,
        page: 1,
        perPage: 8,
    })

    const bindingFilters = reactive({
        toolName: '',
        moduleScope: undefined,
        enabled: undefined,
        page: 1,
        perPage: 8,
    })

    const profileFilters = reactive({
        search: '',
        capabilityClass: undefined,
        page: 1,
        perPage: 8,
    })

    const runFilters = reactive({
        search: '',
        status: undefined,
        moduleScope: undefined,
        page: 1,
        perPage: 6,
    })

    const approvalFilters = reactive({
        status: 'pending',
        applyOutcome: undefined,
        artifactType: undefined,
        page: 1,
        perPage: 6,
    })

    const scheduleFilters = reactive({
        search: '',
        status: 'active',
        profile: undefined,
        toolName: undefined,
        moduleScope: undefined,
        page: 1,
        perPage: 6,
    })

    function resetFilter(filter) {
        const defaults = {
            search: '',
            status: undefined,
            moduleScope: undefined,
            page: 1,
            family: undefined,
            capabilityClass: undefined,
            toolName: '',
            enabled: undefined,
            applyOutcome: undefined,
            artifactType: undefined,
            profile: undefined,
        }
        Object.keys(filter).forEach((key) => {
            if (key === 'perPage') return
            filter[key] = defaults[key] ?? undefined
        })
    }

    return {
        definitionFilters,
        bindingFilters,
        profileFilters,
        runFilters,
        approvalFilters,
        scheduleFilters,
        resetFilter,
    }
}
