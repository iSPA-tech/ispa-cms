import { i18n } from '@/config/i18n'
import { checkGraph } from '@/components/workflows/graphRules'

const t = (key, params = {}) => i18n.global.t(key, params)

const parseWorkflowJson = (value, fallback = []) => {
    if (!value) return fallback
    if (Array.isArray(value)) return value

    if (typeof value === 'string') {
        try {
            return parseWorkflowJson(JSON.parse(value), fallback)
        } catch (error) {
            return fallback
        }
    }

    return fallback
}

const detectNodeTypeFromStep = (data = {}, fallbackType = 'custom') => {
    const cfg = data.step_config || {}
    const nodeType = cfg.node_type
    const stepType = data.step_type

    if (
        (stepType === 'event' && nodeType === 'input') ||
        nodeType === 'input' ||
        stepType === 'input'
    ) {
        return 'input'
    }

    if (nodeType === 'output' || stepType === 'output') {
        return 'output'
    }

    if (stepType === 'decision' || fallbackType === 'decision') {
        return 'decision'
    }

    return fallbackType || 'custom'
}

export const mapWorkflowConnectionsToEdges = (connections = []) =>
    parseWorkflowJson(connections).map((conn) => {
        const condition = conn.condition || {}
        const fromCode = conn.from_step?.step_code || conn.fromStep?.step_code
        const toCode = conn.to_step?.step_code || conn.toStep?.step_code

        if (!fromCode || !toCode) {
            return null
        }

        const sourceHandle = condition.source_handle ?? (
            condition.condition_type === 'equals'
                ? condition.condition_value ?? null
                : null
        )

        return {
            id: conn.id ? `edge-${conn.id}` : `${fromCode}-${toCode}`,
            source: fromCode,
            target: toCode,
            sourceHandle,
            targetHandle: condition.target_handle ?? null,
            data: {
                condition: condition.condition ?? condition.condition_value ?? null,
                condition_type: condition.condition_type ?? null,
                condition_value: condition.condition_value ?? null,
            },
        }
    }).filter(Boolean)

export const getWorkflowGraphDesign = (workflow = {}) => {
    const nodes = parseWorkflowJson(workflow.nodes).map((node) => ({
        ...node,
        type: detectNodeTypeFromStep(node.data || {}, node.type),
    }))
    const storedEdges = parseWorkflowJson(workflow.edges)
    const connectionEdges = mapWorkflowConnectionsToEdges(workflow.connections)
    const edges = storedEdges.length ? storedEdges : connectionEdges

    return { nodes, edges }
}

/**
 * Graph checks with their wording, for the builder's health tab and the list cards.
 * The rules live in `graphRules.checkGraph`; `byType` is the node catalog (`catalog.byType`) when it is loaded.
 */
export const buildWorkflowValidationReport = ({ nodes = [], edges = [], byType = {} } = {}) => {
    const results = Object.fromEntries(checkGraph(nodes, edges, byType).map((check) => [check.key, check]))
    const hasNodes = results['has-nodes']
    const start = results['single-start']
    const decisions = results['decision-branches']
    const connected = results.connected

    const checks = [
        {
            key: 'has-nodes',
            label: t('text.workflow.validation.graph_has_steps'),
            ok: hasNodes.ok,
            message: hasNodes.ok
                ? t('text.workflow.validation.graph_has_steps_ok', { count: hasNodes.count })
                : t('text.workflow.validation.graph_has_steps_empty'),
            nodeIds: [],
        },
        {
            key: 'single-input',
            label: t('text.workflow.validation.single_input'),
            ok: start.ok,
            message: start.ok
                ? t('text.workflow.validation.single_input_ok', { name: start.name })
                : t('text.workflow.validation.single_input_found', { count: start.count }),
            nodeIds: start.nodeIds,
        },
        // Only workflows that still use the old yes/no card have this check.
        ...(decisions.total
            ? [
                  {
                      key: 'decision-branches',
                      label: t('text.workflow.validation.decision_branches'),
                      ok: decisions.ok,
                      message: decisions.ok
                          ? t('text.workflow.validation.decision_branches_ok', { count: decisions.total })
                          : t('text.workflow.validation.decision_branches_missing', { count: decisions.count }),
                      nodeIds: decisions.nodeIds,
                  },
              ]
            : []),
        {
            key: 'connected-nodes',
            label: t('text.workflow.validation.connected_nodes'),
            ok: connected.ok,
            message: connected.ok
                ? t('text.workflow.validation.connected_nodes_ok')
                : t('text.workflow.validation.connected_nodes_missing', { count: connected.count }),
            nodeIds: connected.nodeIds,
        },
    ]

    const failingChecks = checks.filter((check) => !check.ok)

    return {
        checks,
        isValid: failingChecks.length === 0,
        issueCount: failingChecks.length,
    }
}

export const summarizeWorkflowProject = (workflow = {}) => {
    const { nodes, edges } = getWorkflowGraphDesign(workflow)
    const validation = buildWorkflowValidationReport({ nodes, edges })
    const decisionCount = nodes.filter((node) => node.type === 'decision').length
    const inputCount = nodes.filter((node) => node.type === 'input').length
    const outputCount = nodes.filter((node) => node.type === 'output').length

    return {
        nodeCount: nodes.length,
        edgeCount: edges.length,
        decisionCount,
        inputCount,
        outputCount,
        validationState: nodes.length
            ? validation.isValid
                ? t('text.workflow.validation.state_ready')
                : t('text.workflow.validation.state_needs_attention')
            : t('text.workflow.validation.state_not_designed'),
        validationTone: nodes.length
            ? validation.isValid
                ? 'green'
                : 'orange'
            : 'default',
        validation,
    }
}

export const normalizeWorkflowTags = (tags) => {
    if (!tags) return []
    if (Array.isArray(tags)) return tags.filter(Boolean)

    if (typeof tags === 'string') {
        try {
            return normalizeWorkflowTags(JSON.parse(tags))
        } catch (error) {
            return [tags].filter(Boolean)
        }
    }

    if (typeof tags === 'object') {
        return Object.values(tags).filter(Boolean)
    }

    return []
}

export const isSystemManagedWorkflow = (workflow = {}) => {
    const tags = normalizeWorkflowTags(workflow.tags)

    return tags.includes('system-managed') || tags.includes('schedule-wrapper')
}

export const normalizeWorkflowProject = (workflow = {}) => {
    const normalized = {
        ...workflow,
        tags: normalizeWorkflowTags(workflow.tags),
        is_active: !!workflow.is_active,
        connections: parseWorkflowJson(workflow.connections),
        ...getWorkflowGraphDesign(workflow),
        system_managed: isSystemManagedWorkflow(workflow),
    }

    return {
        ...normalized,
        summary: summarizeWorkflowProject(normalized),
    }
}
