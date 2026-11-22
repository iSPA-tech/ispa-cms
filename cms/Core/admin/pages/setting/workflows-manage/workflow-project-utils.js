import { i18n } from '@/config/i18n'

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

export const buildWorkflowValidationReport = ({ nodes = [], edges = [] } = {}) => {
    const nodeList = Array.isArray(nodes) ? nodes : []
    const edgeList = Array.isArray(edges) ? edges : []
    const inputs = nodeList.filter((node) => node.type === 'input')
    const outputs = nodeList.filter((node) => node.type === 'output')
    const decisions = nodeList.filter((node) => node.type === 'decision')
    const nodeIds = new Set(nodeList.map((node) => node.id))
    const adjacency = edgeList.reduce((acc, edge) => {
        if (!nodeIds.has(edge.source) || !nodeIds.has(edge.target)) {
            return acc
        }

        acc[edge.source] = acc[edge.source] || []
        acc[edge.source].push(edge.target)
        return acc
    }, {})
    const indegree = edgeList.reduce((acc, edge) => {
        if (!nodeIds.has(edge.source) || !nodeIds.has(edge.target)) {
            return acc
        }

        acc[edge.target] = (acc[edge.target] || 0) + 1
        return acc
    }, {})

    const decisionIssues = decisions
        .filter((node) => {
            const yesHandle = node.data?.step_config?.yes_output || 'target-a'
            const noHandle = node.data?.step_config?.no_output || 'target-b'
            const outgoing = edgeList.filter((edge) => edge.source === node.id)
            const hasYes = outgoing.some((edge) => edge.sourceHandle === yesHandle)
            const hasNo = outgoing.some((edge) => edge.sourceHandle === noHandle)

            return !hasYes || !hasNo
        })
        .map((node) => node.id)

    const missingOutgoing = nodeList
        .filter((node) => node.type !== 'output')
        .filter((node) => !edgeList.some((edge) => edge.source === node.id))
        .map((node) => node.id)

    const orphanNodes = nodeList
        .filter((node) => node.type !== 'input' && (!indegree[node.id] || indegree[node.id] < 1))
        .map((node) => node.id)

    const visited = new Set()
    const stack = [...inputs.map((node) => node.id)]

    while (stack.length) {
        const current = stack.pop()
        if (visited.has(current)) continue

        visited.add(current)
        ;(adjacency[current] || []).forEach((next) => {
            if (!visited.has(next)) {
                stack.push(next)
            }
        })
    }

    const reachableOutputIds = outputs.filter((output) => visited.has(output.id)).map((output) => output.id)
    const unreachableOutputs = outputs.filter((output) => !visited.has(output.id)).map((output) => output.id)

    const checks = [
        {
            key: 'has-nodes',
            label: t('text.workflow.validation.graph_has_steps'),
            ok: nodeList.length > 0,
            message: nodeList.length
                ? t('text.workflow.validation.graph_has_steps_ok', { count: nodeList.length })
                : t('text.workflow.validation.graph_has_steps_empty'),
            nodeIds: [],
        },
        {
            key: 'single-input',
            label: t('text.workflow.validation.single_input'),
            ok: inputs.length === 1,
            message:
                inputs.length === 1
                    ? t('text.workflow.validation.single_input_ok', {
                          name: inputs[0].data?.name || inputs[0].id,
                      })
                    : t('text.workflow.validation.single_input_found', { count: inputs.length }),
            nodeIds: inputs.map((node) => node.id),
        },
        {
            key: 'has-output',
            label: t('text.workflow.validation.has_output'),
            ok: outputs.length >= 1,
            message:
                outputs.length >= 1
                    ? t('text.workflow.validation.has_output_ok', { count: outputs.length })
                    : t('text.workflow.validation.has_output_empty'),
            nodeIds: outputs.map((node) => node.id),
        },
        {
            key: 'decision-branches',
            label: t('text.workflow.validation.decision_branches'),
            ok: decisionIssues.length === 0,
            message:
                decisionIssues.length === 0
                    ? decisions.length
                        ? t('text.workflow.validation.decision_branches_ok', {
                              count: decisions.length,
                          })
                        : t('text.workflow.validation.decision_branches_none')
                    : t('text.workflow.validation.decision_branches_missing', {
                          count: decisionIssues.length,
                      }),
            nodeIds: decisionIssues,
        },
        {
            key: 'connected-nodes',
            label: t('text.workflow.validation.connected_nodes'),
            ok: orphanNodes.length === 0 && missingOutgoing.length === 0,
            message:
                orphanNodes.length === 0 && missingOutgoing.length === 0
                    ? t('text.workflow.validation.connected_nodes_ok')
                    : t('text.workflow.validation.connected_nodes_missing', {
                          count: new Set([...orphanNodes, ...missingOutgoing]).size,
                      }),
            nodeIds: Array.from(new Set([...orphanNodes, ...missingOutgoing])),
        },
        {
            key: 'input-to-output',
            label: t('text.workflow.validation.input_to_output'),
            ok: reachableOutputIds.length > 0,
            message:
                reachableOutputIds.length > 0
                    ? t('text.workflow.validation.input_to_output_ok', {
                          count: reachableOutputIds.length,
                      })
                    : t('text.workflow.validation.input_to_output_missing'),
            nodeIds: reachableOutputIds.length ? reachableOutputIds : unreachableOutputs,
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
