import { computed } from 'vue'
import { rule } from '@/mixins'

/**
 * Shared permission computeds for all Agentic CMS pages and components.
 * Single source of truth — no page should check permissions directly.
 */
export function useAgenticPermissions() {
    const canViewAgentic = computed(() => rule(['agentic.viewAny', 'workflow.viewAny', 'workflow_project.viewAny']))
    const canProvisionAgentic = computed(() => rule(['agentic.update', 'workflow.update', 'workflow_project.update']))
    const canApproveAgentic = computed(() => rule(['agentic.approve', 'agentic.update']))
    const canOperateAgentic = computed(() => rule(['agentic.update', 'workflow.update']))

    return { canViewAgentic, canProvisionAgentic, canApproveAgentic, canOperateAgentic }
}
