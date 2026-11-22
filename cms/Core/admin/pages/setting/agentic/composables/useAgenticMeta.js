import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { agenticArtifactTypeLabelKey, agenticModules, agenticTasks } from '../registry'

/**
 * All static metadata, option arrays, column definitions, status helpers,
 * task card mappings, and tooltip glossary for the Agentic CMS UI.
 *
 * Reused by: Dashboard, Inbox, AdminConfig, InlineAssist, child components.
 */
export function useAgenticMeta() {
    const { t, te } = useI18n()

    // ── Capability class meta ──────────────────────────────────────
    const capabilityMetaMap = computed(() => ({
        class_0_read_only: {
            value: 'class_0_read_only',
            label: t('text.agentic.capability.class_0_label'),
            shortLabel: t('text.agentic.capability.short_class_0'),
            summary: t('text.agentic.capability.class_0_summary'),
            scheduleAllowed: false,
        },
        class_1_draft_generation: {
            value: 'class_1_draft_generation',
            label: t('text.agentic.capability.class_1_label'),
            shortLabel: t('text.agentic.capability.short_class_1'),
            summary: t('text.agentic.capability.class_1_summary'),
            scheduleAllowed: false,
        },
        class_2_staged_mutation: {
            value: 'class_2_staged_mutation',
            label: t('text.agentic.capability.class_2_label'),
            shortLabel: t('text.agentic.capability.short_class_2'),
            summary: t('text.agentic.capability.class_2_summary'),
            scheduleAllowed: false,
        },
        class_3_autonomous_maintenance: {
            value: 'class_3_autonomous_maintenance',
            label: t('text.agentic.capability.class_3_label'),
            shortLabel: t('text.agentic.capability.short_class_3'),
            summary: t('text.agentic.capability.class_3_summary'),
            scheduleAllowed: true,
        },
    }))

    const capabilityOptions = computed(() =>
        Object.values(capabilityMetaMap.value).map((item) => ({
            label: item.label,
            value: item.value,
        })),
    )

    // ── Budget preset meta ─────────────────────────────────────────
    const budgetPresetMetaMap = computed(() => ({
        strict: {
            value: 'strict',
            label: t('text.agentic.budget.strict_label'),
            summary: t('text.agentic.budget.strict_summary'),
            maxSteps: 3,
            config: { max_steps: 3 },
        },
        standard: {
            value: 'standard',
            label: t('text.agentic.budget.standard_label'),
            summary: t('text.agentic.budget.standard_summary'),
            maxSteps: 5,
            config: { max_steps: 5 },
        },
        extended: {
            value: 'extended',
            label: t('text.agentic.budget.extended_label'),
            summary: t('text.agentic.budget.extended_summary'),
            maxSteps: 8,
            config: { max_steps: 8 },
        },
        deep_review: {
            value: 'deep_review',
            label: t('text.agentic.budget.deep_review_label'),
            summary: t('text.agentic.budget.deep_review_summary'),
            maxSteps: 12,
            config: { max_steps: 12 },
        },
        custom_existing: {
            value: 'custom_existing',
            label: t('text.agentic.budget.keep_existing_label'),
            summary: t('text.agentic.budget.keep_existing_summary'),
            maxSteps: null,
            config: null,
        },
    }))

    const budgetPresetOptions = computed(() =>
        Object.values(budgetPresetMetaMap.value).map((item) => ({
            label: item.label,
            value: item.value,
        })),
    )

    // ── Schedule cadence options ────────────────────────────────────
    const scheduleCadenceOptions = computed(() => [
        { label: t('text.agentic.cadence.hourly_1'), value: 60 },
        { label: t('text.agentic.cadence.hourly_4'), value: 240 },
        { label: t('text.agentic.cadence.daily'), value: 1440 },
    ])

    // ── Status options (for filters) ───────────────────────────────
    const approvalStatusOptions = computed(() => [
        { label: t('text.agentic.label.pending'), value: 'pending' },
        { label: t('text.agentic.label.approved'), value: 'approved' },
        { label: t('text.agentic.label.rejected'), value: 'rejected' },
        { label: t('text.agentic.label.stale'), value: 'stale' },
        { label: t('text.agentic.label.failed'), value: 'failed' },
    ])

    const applyOutcomeOptions = computed(() => [
        { label: t('text.agentic.status.awaiting_apply'), value: 'awaiting_apply' },
        { label: t('text.agentic.label.applied'), value: 'applied' },
        { label: t('text.agentic.status.apply_failed'), value: 'apply_failed' },
        { label: t('text.agentic.status.approval_stale'), value: 'approval_stale' },
        { label: t('text.agentic.label.rejected'), value: 'approval_rejected' },
    ])

    const runStatusOptions = computed(() => [
        { label: t('text.agentic.label.running'), value: 'running' },
        { label: t('text.agentic.status.awaiting_approval'), value: 'awaiting_approval' },
        { label: t('text.agentic.status.awaiting_apply'), value: 'awaiting_apply' },
        { label: t('text.agentic.label.applied'), value: 'applied' },
        { label: t('text.agentic.label.cancelled'), value: 'cancelled' },
        { label: t('text.agentic.label.completed'), value: 'completed' },
        { label: t('text.agentic.label.rejected'), value: 'rejected' },
        { label: t('text.agentic.label.failed'), value: 'failed' },
    ])

    const scheduleStatusOptions = computed(() => [
        { label: t('text.agentic.schedules.status.active'), value: 'active' },
        { label: t('text.agentic.schedules.status.running'), value: 'running' },
        { label: t('text.agentic.schedules.status.paused'), value: 'paused' },
    ])

    const profileStatusOptions = computed(() => [
        { label: t('text.agentic.label.active'), value: 'active' },
        { label: t('text.agentic.label.inactive'), value: 'inactive' },
    ])

    const enabledOptions = computed(() => [
        { label: t('text.agentic.enabled'), value: 'true' },
        { label: t('text.agentic.disabled'), value: 'false' },
    ])

    // ── Column definitions ─────────────────────────────────────────
    const definitionColumns = computed(() => [
        { key: 'tool_name', title: t('text.agentic.col.tool'), dataIndex: 'tool_name', width: 320 },
        { key: 'tool_family', title: t('text.agentic.col.family'), dataIndex: 'tool_family', width: 130 },
        { key: 'contract_steward', title: t('text.agentic.col.steward'), dataIndex: 'contract_steward', width: 130 },
        { key: 'governance', title: t('text.agentic.col.governance'), width: 240 },
        { key: 'binding_count', title: t('text.agentic.col.bindings'), dataIndex: 'binding_count', width: 90 },
        { key: 'profile_count', title: t('text.agentic.col.profiles'), dataIndex: 'profile_count', width: 90 },
        { key: 'select', title: t('text.agentic.col.select'), width: 92 },
    ])

    const bindingColumns = computed(() => [
        { key: 'tool_name', title: t('text.agentic.col.tool'), dataIndex: 'tool_name', width: 250 },
        { key: 'binding_target', title: t('text.agentic.binding_target'), dataIndex: 'binding_target', width: 520 },
        { key: 'scope', title: t('text.agentic.col.scope'), width: 180 },
        { key: 'enabled', title: t('text.agentic.col.enabled'), dataIndex: 'enabled', width: 90 },
        { key: 'actions', title: t('text.agentic.col.actions'), width: 170 },
    ])

    const profileColumns = computed(() => [
        { key: 'name', title: t('text.agentic.col.profile'), dataIndex: 'name' },
        { key: 'capability_class', title: t('text.agentic.col.capability'), dataIndex: 'capability_class', width: 180 },
        { key: 'status', title: t('text.agentic.col.status'), dataIndex: 'status', width: 110 },
        { key: 'tool_definition_count', title: t('text.agentic.col.tools'), dataIndex: 'tool_definition_count', width: 80 },
        { key: 'run_count', title: t('text.agentic.col.runs'), dataIndex: 'run_count', width: 80 },
        { key: 'inspect', title: t('text.agentic.col.actions'), width: 170 },
    ])

    const approvalColumns = computed(() => [
        { key: 'summary', title: t('text.agentic.inbox.proposal') },
        { key: 'status', title: t('text.agentic.col.status'), width: 140 },
        { key: 'diff', title: t('text.agentic.col.diff') },
        { key: 'actions', title: t('text.agentic.col.actions'), width: 220 },
    ])

    const runColumns = computed(() => [
        { key: 'goal', title: t('text.agentic.col.run'), width: 240 },
        { key: 'status', title: t('text.agentic.col.status'), width: 120 },
        { key: 'workflow', title: t('text.agentic.col.workflow'), width: 160 },
        { key: 'latest_step', title: t('text.agentic.latest_step') },
        { key: 'inspect', title: t('text.agentic.col.actions'), width: 210 },
    ])

    const scheduleColumns = computed(() => [
        { key: 'summary', title: t('text.agentic.schedules.column.schedule'), width: 250 },
        { key: 'cadence', title: t('text.agentic.schedules.column.cadence'), width: 140 },
        { key: 'last_run', title: t('text.agentic.last_run') },
        { key: 'actions', title: t('text.agentic.schedules.column.actions'), width: 240 },
    ])

    // ── Status CSS class helpers ───────────────────────────────────
    function runtimeStatusClass(status) {
        const map = {
            running: 'agentic-pill--info',
            completed: 'agentic-pill--ok',
            applied: 'agentic-pill--ok',
            failed: 'agentic-pill--danger',
            cancelled: 'agentic-pill--muted',
            rejected: 'agentic-pill--danger',
            awaiting_approval: 'agentic-pill--warn',
            awaiting_apply: 'agentic-pill--warn',
            pending: 'agentic-pill--warn',
            approved: 'agentic-pill--ok',
            approval_stale: 'agentic-pill--danger',
            apply_failed: 'agentic-pill--danger',
        }
        return map[status] || 'agentic-pill--muted'
    }

    function healthStatusClass(status) {
        if (status === 'ok') return 'agentic-pill--ok'
        if (status === 'warn') return 'agentic-pill--warn'
        if (status === 'error') return 'agentic-pill--danger'
        return 'agentic-pill--muted'
    }

    // ── Task card meta (tool_name → human-readable) ────────────────
    // targetEntity: null = no target needed, 'post' = select post, 'product' = select product
    const taskCardMeta = {
        // Content — read-only
        'content.latest_posts': { icon: 'file-text', titleKey: 'text.agentic.task.latest_posts', descKey: 'text.agentic.task.latest_posts_desc', module: 'content', group: 'research', targetEntity: null },
        'content.search_posts': { icon: 'search', titleKey: 'text.agentic.task.search_posts', descKey: 'text.agentic.task.search_posts_desc', module: 'content', group: 'research', targetEntity: null },
        'content.summarize_post': { icon: 'align-left', titleKey: 'text.agentic.task.summarize_post', descKey: 'text.agentic.task.summarize_post_desc', module: 'content', group: 'research', targetEntity: 'post' },
        'content.suggest_internal_links': { icon: 'link', titleKey: 'text.agentic.task.suggest_links', descKey: 'text.agentic.task.suggest_links_desc', module: 'content', group: 'research', targetEntity: 'post' },
        // Content — proposals
        'content.propose_post_description_refresh': { icon: 'edit', titleKey: 'text.agentic.task.propose_description', descKey: 'text.agentic.task.propose_description_desc', module: 'content', group: 'proposal', targetEntity: 'post' },
        'content.propose_post_seo_refresh': { icon: 'trending-up', titleKey: 'text.agentic.task.propose_seo', descKey: 'text.agentic.task.propose_seo_desc', module: 'content', group: 'proposal', targetEntity: 'post' },
        // Product — read-only
        'product.search_products': { icon: 'search', titleKey: 'text.agentic.task.search_products', descKey: 'text.agentic.task.search_products_desc', module: 'product', group: 'research', targetEntity: null },
        'product.inventory_alerts_summary': { icon: 'alert-triangle', titleKey: 'text.agentic.task.inventory_alerts', descKey: 'text.agentic.task.inventory_alerts_desc', module: 'product', group: 'research', targetEntity: null },
        'product.explain_inventory_status': { icon: 'bar-chart', titleKey: 'text.agentic.task.explain_inventory', descKey: 'text.agentic.task.explain_inventory_desc', module: 'product', group: 'research', targetEntity: 'product' },
        'product.inspect_catalog_normalization': { icon: 'check-square', titleKey: 'text.agentic.task.inspect_catalog', descKey: 'text.agentic.task.inspect_catalog_desc', module: 'product', group: 'research', targetEntity: null },
        // Product — proposals
        'product.propose_product_seo_refresh': { icon: 'trending-up', titleKey: 'text.agentic.task.propose_product_seo', descKey: 'text.agentic.task.propose_product_seo_desc', module: 'product', group: 'proposal', targetEntity: 'product' },
        'product.propose_product_description_refresh': { icon: 'edit', titleKey: 'text.agentic.task.propose_product_desc', descKey: 'text.agentic.task.propose_product_desc_desc', module: 'product', group: 'proposal', targetEntity: 'product' },
        'product.propose_product_catalog_normalization': { icon: 'layers', titleKey: 'text.agentic.task.propose_catalog_norm', descKey: 'text.agentic.task.propose_catalog_norm_desc', module: 'product', group: 'proposal', targetEntity: null },
        'product.propose_product_content_refresh': { icon: 'file-plus', titleKey: 'text.agentic.task.propose_product_content', descKey: 'text.agentic.task.propose_product_content_desc', module: 'product', group: 'proposal', targetEntity: 'product' },
        'product.propose_product_custom_flags_refresh': { icon: 'flag', titleKey: 'text.agentic.task.propose_product_flags', descKey: 'text.agentic.task.propose_product_flags_desc', module: 'product', group: 'proposal', targetEntity: 'product' },
        // Education
        'education.summarize_ai_evaluation': { icon: 'clipboard', titleKey: 'text.agentic.task.summarize_eval', descKey: 'text.agentic.task.summarize_eval_desc', module: 'education', group: 'research', targetEntity: null },
        'education.explain_ai_evaluation_scorecard': { icon: 'award', titleKey: 'text.agentic.task.explain_scorecard', descKey: 'text.agentic.task.explain_scorecard_desc', module: 'education', group: 'research', targetEntity: null },
        'education.propose_ai_evaluation_completion': { icon: 'check-circle', titleKey: 'text.agentic.task.propose_eval', descKey: 'text.agentic.task.propose_eval_desc', module: 'education', group: 'proposal', targetEntity: null },
        // Task cards a module contributes for its own tools.
        ...agenticTasks(),
    }

    // ── Help tooltip glossary (term -> i18n key) ───────────────────
    const helpTerms = {
        capability_class: 'text.agentic.help.capability_class',
        approval: 'text.agentic.help.approval',
        budget: 'text.agentic.help.budget',
        profile: 'text.agentic.help.profile',
        tool: 'text.agentic.help.tool',
        artifact: 'text.agentic.help.artifact',
        schedule: 'text.agentic.help.schedule',
    }

    // ── Human-readable artifact type names ─────────────────────────
    function artifactTypeLabel(artifactType) {
        const map = {
            content_post_description_patch: t('text.agentic.task.propose_description'),
            content_post_seo_patch: t('text.agentic.task.propose_seo'),
            product_seo_patch: t('text.agentic.task.propose_product_seo'),
            product_description_patch: t('text.agentic.task.propose_product_desc'),
            product_catalog_normalization_patch: t('text.agentic.task.propose_catalog_norm'),
            product_content_patch: t('text.agentic.task.propose_product_content'),
            product_custom_flags_patch: t('text.agentic.task.propose_product_flags'),
            education_ai_evaluation_patch: t('text.agentic.task.propose_eval'),
            ticket_priority_patch: t('text.agentic.artifact.ticket_priority_patch'),
            tool_result: t('text.agentic.label.tool_result'),
        }
        const contributed = agenticArtifactTypeLabelKey(artifactType)
        // Never show the raw code: an unknown type reads as a generic proposal.
        return map[artifactType] || (contributed ? t(contributed) : t('text.agentic.artifact.unknown'))
    }

    // ── Module badge label ─────────────────────────────────────────
    function moduleLabel(moduleScope) {
        const map = {
            content: t('text.agentic.module.content'),
            product: t('text.agentic.module.product'),
            education: t('text.agentic.module.education'),
            core: t('text.agentic.module.core'),
        }
        // Modules that registered themselves. The label of a module that is not on this site does not
        // exist: neutral wording, never the raw key.
        for (const module of agenticModules()) {
            const label = te(module.labelKey) ? t(module.labelKey) : t('text.agentic.module.other')
            for (const key of [module.key, ...(module.aliases || [])]) {
                map[key] ??= label
            }
        }
        return map[moduleScope] || moduleScope
    }

    function formatMeta(meta = {}) {
        const total = meta.total || 0
        const currentPage = meta.page || 1
        const lastPage = meta.last_page || 1
        return t('text.agentic.pagination_meta', { total, current: currentPage, last: lastPage })
    }

    /**
     * Translate a raw status/enum value into a human-readable label.
     * Falls back to 'n/a' for nullish or empty values, and to the raw
     * value itself when no translation key exists.
     *
     * @param {string | null | undefined} value
     * @returns {string}
     */
    function sl(value) {
        if (!value) return t('text.agentic.na')
        const key = `text.agentic.label.${value}`
        return t(key, value)
    }

    return {
        sl,
        formatMeta,
        capabilityMetaMap,
        capabilityOptions,
        budgetPresetMetaMap,
        budgetPresetOptions,
        scheduleCadenceOptions,
        approvalStatusOptions,
        applyOutcomeOptions,
        runStatusOptions,
        scheduleStatusOptions,
        profileStatusOptions,
        enabledOptions,
        definitionColumns,
        bindingColumns,
        profileColumns,
        approvalColumns,
        runColumns,
        scheduleColumns,
        runtimeStatusClass,
        healthStatusClass,
        taskCardMeta,
        helpTerms,
        artifactTypeLabel,
        moduleLabel,
    }
}
