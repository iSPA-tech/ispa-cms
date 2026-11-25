import { describe, expect, it } from 'vitest'
import {
    buildWorkflowValidationReport,
    mapWorkflowConnectionsToEdges,
    normalizeWorkflowProject,
    normalizeWorkflowTags,
    summarizeWorkflowProject,
} from '../../../cms/Core/admin/pages/setting/workflows-manage/workflow-project-utils'

describe('workflow project utils', () => {
    it('normalizes non-array tags before deriving system-managed state', () => {
        const workflow = normalizeWorkflowProject({
            id: 19,
            tags: { primary: 'agentic', secondary: 'schedule-wrapper' },
            nodes: '[{"id":"start"}]',
            edges: '[{"id":"edge-1"}]',
        })

        expect(workflow.tags).toEqual(['agentic', 'schedule-wrapper'])
        expect(workflow.system_managed).toBe(true)
        expect(workflow.nodes).toEqual([{ id: 'start', type: 'custom' }])
        expect(workflow.edges).toEqual([{ id: 'edge-1' }])
    })

    it('accepts JSON-encoded tag payloads', () => {
        expect(normalizeWorkflowTags('["system-managed","agentic"]')).toEqual([
            'system-managed',
            'agentic',
        ])
    })

    it('maps legacy workflow connections into builder edges', () => {
        expect(
            mapWorkflowConnectionsToEdges([
                {
                    id: 7,
                    from_step: { step_code: 'decision_1' },
                    to_step: { step_code: 'notify_team' },
                    condition: {
                        condition_type: 'equals',
                        condition_value: 'target-a',
                    },
                },
            ])
        ).toEqual([
            expect.objectContaining({
                id: 'edge-7',
                source: 'decision_1',
                target: 'notify_team',
                sourceHandle: 'target-a',
            }),
        ])
    })

    it('summarizes graph counts and validation state', () => {
        const summary = summarizeWorkflowProject({
            nodes: [
                { id: 'start', type: 'input', data: { name: 'Start' } },
                // A yes/no card made on the canvas stores its handles (useDnd); target-a / target-b link it.
                { id: 'gate', type: 'decision', data: { step_config: { yes_output: 'target-a', no_output: 'target-b' } } },
                { id: 'finish', type: 'output', data: { name: 'Finish' } },
            ],
            edges: [
                { source: 'start', target: 'gate' },
                { source: 'gate', target: 'finish', sourceHandle: 'target-a' },
                { source: 'gate', target: 'finish', sourceHandle: 'target-b' },
            ],
        })

        expect(summary.nodeCount).toBe(3)
        expect(summary.edgeCount).toBe(3)
        expect(summary.decisionCount).toBe(1)
        expect(summary.validationState).toBe('text.workflow.validation.state_ready')
    })

    it('reports blocking graph issues when input cannot reach output', () => {
        const report = buildWorkflowValidationReport({
            nodes: [
                { id: 'start', type: 'input', data: {} },
                { id: 'middle', type: 'custom', data: {} },
                { id: 'finish', type: 'output', data: {} },
            ],
            edges: [{ source: 'start', target: 'middle' }],
        })

        expect(report.isValid).toBe(false)
        expect(report.issueCount).toBeGreaterThan(0)
        // The designer refactor (2026-11-24) folded the old input-to-output check into "every step is connected".
        expect(report.checks.find((check) => check.key === 'connected-nodes')?.ok).toBe(false)
    })
})
